<?php

namespace App\Actions\Inventory;

use App\Models\AuditEvent;
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
        $corrections = AuditEvent::where('action', 'inventory.document_corrected')->whereIn('id', $movements->where('source_type', AuditEvent::class)->pluck('source_id'))->get()->keyBy('id');
        $journals = DB::table('journal_entries')->whereIn('branch_id', $stocks->pluck('branch_id'))
            ->where(function ($query) use ($opening, $purchases, $corrections): void {
                $query->where(fn ($nested) => $nested->where('source_type', OpeningStockEntry::class)->whereIn('source_id', $opening->pluck('opening_stock_entry_id')))
                    ->orWhere(fn ($nested) => $nested->where('source_type', PurchaseInvoice::class)->whereIn('source_id', $purchases->pluck('purchase_invoice_id')))
                    ->orWhere(fn ($nested) => $nested->where('source_type', AuditEvent::class)->whereIn('source_id', $corrections->modelKeys()));
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
                if ($movement->source_type === AuditEvent::class) {
                    $event = $corrections->get($movement->source_id);
                    $entries = $journals->get(AuditEvent::class.':'.$movement->source_id, collect());
                    $key = AuditEvent::class.':'.$movement->source_id;
                    $posted = $entries->contains(fn ($entry): bool => $entry->event === 'document_correction');
                    $valid = $event && $movement->movement_type === 'document_correction' && $posted && ! isset($seen[$key])
                        && (int) $event->branch_id === (int) $stock->branch_id && (int) $event->data['variant_id'] === (int) $stock->product_variant_id
                        && (int) $event->data['quantity_delta'] === (int) $movement->quantity_change
                        && $candidate !== null && (int) $event->data['inventory_delta_minor'] === (int) $candidate;
                    $seen[$key] = true;
                    $complete = $complete && $valid;
                    if ($candidate !== null) {
                        $value = Money::add($value, (int) $candidate);
                    }
                    $problems = $valid ? [] : ['قيد التصحيح المستقل أو حركته غير مثبتين.'];
                    $issues = array_merge($issues, $problems);
                    $sources[] = ['movement_id' => $movement->id, 'kind' => $movement->movement_type, 'quantity' => $movement->quantity_change,
                        'recorded_value_minor' => $candidate, 'evidence_value_minor' => $valid ? $candidate : null, 'source_type' => $movement->source_type, 'source_id' => $movement->source_id,
                        'number' => $event ? 'COR-'.$event->id : null, 'url' => $event ? route('inventory.document-corrections.show', [$event->subject_type === PurchaseInvoice::class ? 'purchase' : 'opening', $event->subject_id, $event->data['item_id']]) : null,
                        'document_status' => $valid ? 'approved' : null, 'document_date' => $event?->data['date'] ?? null, 'financial_posted' => $valid, 'journal_ids' => $entries->pluck('id')->all(), 'issues' => $problems];

                    continue;
                }
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
