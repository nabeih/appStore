<?php

namespace App\Actions\Inventory;

use App\Models\Inventory;
use App\Models\OpeningStockEntry;
use App\Models\OpeningStockItem;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\StockMovement;
use App\Support\Sales\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryReadinessReport
{
    /** @param Collection<int, Inventory> $stocks */
    public function annotate(Collection $stocks): Collection
    {
        $movements = StockMovement::whereIn('branch_id', $stocks->pluck('branch_id'))
            ->whereIn('product_variant_id', $stocks->pluck('product_variant_id'))->where('stock_bucket', 'available')->orderBy('id')->get();
        $opening = OpeningStockItem::with('entry')->whereIn('id', $movements->where('source_type', OpeningStockItem::class)->pluck('source_id'))->get()->keyBy('id');
        $purchases = PurchaseInvoiceItem::with('invoice')->whereIn('id', $movements->where('source_type', PurchaseInvoiceItem::class)->pluck('source_id'))->get()->keyBy('id');
        $journals = DB::table('journal_entries')->whereIn('branch_id', $stocks->pluck('branch_id'))
            ->where(function ($query) use ($opening, $purchases): void {
                $query->where(fn ($nested) => $nested->where('source_type', OpeningStockEntry::class)->whereIn('source_id', $opening->pluck('opening_stock_entry_id')))
                    ->orWhere(fn ($nested) => $nested->where('source_type', PurchaseInvoice::class)->whereIn('source_id', $purchases->pluck('purchase_invoice_id')));
            })->whereNotIn('id', DB::table('journal_entries')->whereNotNull('reversal_of_id')->select('reversal_of_id'))->get()
            ->groupBy(fn ($row): string => $row->source_type.':'.$row->source_id);
        $groups = $movements->groupBy(fn (StockMovement $movement): string => $movement->branch_id.':'.$movement->product_variant_id);
        foreach ($stocks as $stock) {
            $history = $groups->get($stock->branch_id.':'.$stock->product_variant_id, collect());
            $complete = $history->isNotEmpty() || (int) $stock->quantity === 0;
            $value = 0;
            $sources = $issues = $seen = [];
            foreach ($history as $movement) {
                $candidate = $movement->value_change_minor;
                $source = match ($movement->source_type) {
                    OpeningStockItem::class => $opening->get($movement->source_id),
                    PurchaseInvoiceItem::class => $purchases->get($movement->source_id),
                    default => null,
                };
                $document = $source instanceof OpeningStockItem ? $source->entry : $source?->invoice;
                $sourceIssues = [];
                if ($source) {
                    $key = $movement->source_type.':'.$movement->source_id;
                    $valid = $document?->status === 'approved' && (int) $document->branch_id === (int) $stock->branch_id
                        && (int) $source->product_variant_id === (int) $stock->product_variant_id
                        && (int) $source->quantity === (int) $movement->quantity_change && ! isset($seen[$key]);
                    $seen[$key] = true;
                    $documentValue = $source instanceof OpeningStockItem ? Money::minor((string) $source->line_total) : (int) $source->line_total_minor;
                    if (! $valid || ($candidate !== null && (int) $candidate !== $documentValue)) {
                        $complete = false;
                        $sourceIssues[] = 'المستند غير مطابق للحركة أو مكرر أو غير معتمد.';
                    } elseif ($candidate === null) {
                        $candidate = $documentValue;
                    }
                } elseif ($candidate === null || in_array($movement->source_type, [OpeningStockItem::class, PurchaseInvoiceItem::class], true)) {
                    $complete = false;
                    $sourceIssues[] = 'قيمة الحركة ومصدرها الداعم غير مكتملين.';
                }
                if ($candidate !== null) {
                    $value = Money::add($value, Money::integer((string) $candidate));
                }
                $financialEntries = $document ? $journals->get($document->getMorphClass().':'.$document->id, collect()) : collect();
                $posted = $document && $financialEntries->contains(fn ($entry): bool => $entry->event === ($source instanceof OpeningStockItem ? 'opening' : 'purchase'))
                    && ! $financialEntries->contains(fn ($entry): bool => $entry->reversal_of_id !== null);
                $sources[] = ['movement_id' => $movement->id, 'kind' => $movement->movement_type, 'quantity' => $movement->quantity_change,
                    'recorded_value_minor' => $movement->value_change_minor, 'evidence_value_minor' => $candidate,
                    'source_type' => $movement->source_type, 'source_id' => $movement->source_id,
                    'number' => $document?->number,
                    'url' => $document ? route($source instanceof OpeningStockItem ? 'inventory.opening-stock.show' : 'purchases.invoices.show', $document) : null,
                    'document_status' => $document?->status, 'document_date' => $document?->opening_date?->toDateString() ?? $document?->invoice_date?->toDateString(),
                    'financial_posted' => (bool) $posted, 'journal_ids' => $financialEntries->pluck('id')->all(), 'issues' => $sourceIssues];
                $issues = array_merge($issues, $sourceIssues);
            }
            $quantity = (int) $history->sum('quantity_change');
            $difference = (int) $stock->quantity - $quantity;
            $reconstructed = $complete && $difference === 0 && $value >= 0 ? $value : null;
            if ($difference !== 0) {
                $issues[] = 'فرق كمية؛ راجع المستند المصدر والجرد قبل اقتراح أي تسوية.';
            }
            if ($stock->value_minor === null) {
                $issues[] = 'قيمة المخزون غير معتمدة.';
            }
            $state = $difference !== 0 ? 'quantity_review' : (! $complete ? 'history_review'
                : ($stock->value_minor !== null ? ($reconstructed !== null && (int) $stock->value_minor !== $reconstructed ? 'value_conflict' : 'reviewed')
                : ($reconstructed !== null ? 'reconstructable' : 'owner_evidence')));
            if ($state === 'value_conflict') {
                $issues[] = 'القيمة الحالية تختلف عن القيمة المدعومة بالحركات والمستندات.';
            }
            $stock->setAttribute('readiness', ['state' => $state, 'history_quantity' => $quantity, 'difference' => $difference,
                'recommended_action' => $difference !== 0 ? 'راجع الجرد ومستند أول المدة أو الشراء المصدر؛ لا تعتمد قيمة أو تحذف فرق الكمية.'
                    : (! $complete ? 'استكمل المستند المصدر واربط الحركة الصحيحة قبل اعتماد القيمة.' : ($stock->value_minor === null ? 'راجع القيمة المعاد بناؤها وأدلتها ثم اعتمدها بصلاحيتك؛ راجع الترحيل المالي بشكل مستقل.' : 'تحقق من الترحيل المالي والمطابقة قبل إنهاء الإعداد.')),
                'recorded_value_minor' => $stock->value_minor, 'reconstructed_value_minor' => $reconstructed,
                'history_value_complete' => $complete, 'issues' => array_values(array_unique($issues)), 'sources' => $sources]);
        }

        return $stocks;
    }
}
