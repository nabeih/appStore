<?php

namespace App\Actions\Inventory;

use App\Models\AuditEvent;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\OpeningStockItem;
use App\Models\PurchaseInvoiceItem;
use App\Models\SaleItem;
use App\Models\SaleReturnItem;
use App\Models\StockMovement;
use App\Support\Sales\Money;
use Illuminate\Support\Collection;

class ProgressiveStockProof
{
    /** Read-only provenance: a recorded stock value alone is never financial proof. */
    public function build(int $branchId): array
    {
        $stocks = Inventory::where('branch_id', $branchId)->with(['productVariant.product.category', 'productVariant.color', 'productVariant.size'])->orderBy('id')->get();
        $movements = StockMovement::where('branch_id', $branchId)->where('stock_bucket', 'available')->orderBy('id')->get();
        $sources = [
            OpeningStockItem::class => OpeningStockItem::with('entry.items')->whereIn('id', $movements->where('source_type', OpeningStockItem::class)->pluck('source_id'))->get()->keyBy('id'),
            PurchaseInvoiceItem::class => PurchaseInvoiceItem::with('invoice.items')->whereIn('id', $movements->where('source_type', PurchaseInvoiceItem::class)->pluck('source_id'))->get()->keyBy('id'),
            SaleItem::class => SaleItem::with('invoice.items')->whereIn('id', $movements->where('source_type', SaleItem::class)->pluck('source_id'))->get()->keyBy('id'),
            SaleReturnItem::class => SaleReturnItem::with(['saleReturn.items', 'saleItem'])->whereIn('id', $movements->where('source_type', SaleReturnItem::class)->pluck('source_id'))->get()->keyBy('id'),
        ];
        $journals = JournalEntry::where('branch_id', $branchId)->with('lines.account')->get();
        $bySource = $journals->groupBy(fn ($journal) => $journal->source_type.':'.$journal->source_id.':'.$journal->event);
        $reversals = $journals->whereNotNull('reversal_of_id')->groupBy('reversal_of_id');
        $confirmations = AuditEvent::where('subject_type', Inventory::class)->whereIn('subject_id', $stocks->modelKeys())
            ->whereIn('action', ['operation.zero_opening_confirmed', 'operation.zero_cost_confirmed'])->get()->groupBy('subject_id');
        $rows = [];
        $accounted = 0;
        $seen = [];
        foreach ($stocks as $stock) {
            $variant = $stock->productVariant;
            $history = $movements->where('product_variant_id', $stock->product_variant_id);
            $reasons = [];
            $add = function (bool $when, string $code, string $message) use (&$reasons): void {
                if ($when) {
                    $reasons[$code] = ['code' => $code, 'message' => $message];
                }
            };
            $add(! $variant || ! $variant->is_active || ! $variant->product?->is_active || ! $variant->product?->category?->is_active
                || ! $variant->color?->is_active || ! $variant->size?->is_active, 'inactive_variant', 'الصنف أو تصنيفه أو لونه أو مقاسه غير فعال.');
            $add($stock->value_minor === null, 'unknown_cost', 'تكلفة هذا الصنف غير مكتملة.');
            $add((int) $stock->quantity < 0 || ($stock->value_minor !== null && (int) $stock->value_minor < 0), 'invalid_stock', 'يوجد رصيد مخزني غير صالح.');
            $add((int) $stock->quantity !== (int) $history->sum('quantity_change'), 'quantity_mismatch', 'يوجد فرق كمية يحتاج مراجعة المستند المصدر.');
            $unknown = $history->contains(fn ($movement) => $movement->value_change_minor === null);
            $add($unknown || ($stock->value_minor !== null && (int) $stock->value_minor !== (int) $history->sum('value_change_minor')), 'value_mismatch', 'قيمة الرصيد لا تطابق حركاته المعروفة.');
            $hasOpening = false;
            $documents = [];
            foreach ($history as $movement) {
                $source = ($sources[$movement->source_type] ?? collect())->get($movement->source_id);
                $proof = $source ? $this->source($source, $movement) : null;
                $key = $movement->source_type.':'.$movement->source_id.':'.$movement->movement_type;
                $duplicate = isset($seen[$key]);
                $seen[$key] = true;
                $valid = $proof && ! $duplicate && $proof['variant_id'] === (int) $stock->product_variant_id
                    && $proof['quantity'] === (int) $movement->quantity_change
                    && $movement->value_change_minor !== null && $proof['value'] === (int) $movement->value_change_minor;
                $journal = null;
                if ($valid) {
                    $matches = $bySource->get($proof['document']->getMorphClass().':'.$proof['document']->id.':'.$proof['event'], collect());
                    $journal = $matches->count() === 1 ? $matches->first() : null;
                    if ($proof['reverse']) {
                        $matches = $journal ? $reversals->get($journal->id, collect()) : collect();
                        $journal = $matches->count() === 1 ? $matches->first() : null;
                    }
                    $valid = $journal && $journal->lines->sum('debit_minor') === $journal->lines->sum('credit_minor')
                        && $this->inventoryAmount($journal->lines) === $proof['document_value']
                        && (int) $proof['document']->branch_id === $branchId
                        && in_array($proof['document']->status, ['approved', 'reversed'], true);
                }
                if ($valid) {
                    $accounted = Money::add($accounted, (int) $movement->value_change_minor);
                    if ($movement->movement_type === 'opening_stock') {
                        $hasOpening = true;
                    }
                } else {
                    $add(true, 'financial_source_unproven', 'لم تكتمل المطابقة المالية أو المستندية لهذا الصنف؛ راجع مصدر الحركة.');
                }
                $documents[] = ['movement_id' => $movement->id, 'kind' => $movement->movement_type,
                    'number' => $proof ? $proof['document']->number : null, 'financial_verified' => (bool) $valid];
            }
            $events = $confirmations->get($stock->id, collect());
            $add(! $hasOpening && ! $events->contains('action', 'operation.zero_opening_confirmed'), 'opening_unknown', 'لم يُعتمد افتتاح هذا الصنف أو توثيق بداية صفر له.');
            $add($stock->value_minor !== null && (int) $stock->value_minor === 0 && (int) $stock->quantity > 0
                && ! $events->contains('action', 'operation.zero_cost_confirmed'), 'zero_cost_unconfirmed', 'التكلفة صفر وتحتاج دليلًا واعتمادًا صريحًا؛ الصفر ليس تكلفة مجهولة.');
            $available = (int) $stock->quantity - (int) ($stock->reserved_quantity ?? 0);
            $rows[$stock->product_variant_id] = ['inventory_id' => $stock->id, 'variant_id' => $stock->product_variant_id,
                'sku' => $variant?->sku, 'name' => $variant?->product?->name, 'quantity' => (int) $stock->quantity,
                'available' => $available, 'value_minor' => $stock->value_minor === null ? null : (int) $stock->value_minor,
                'ready' => $reasons === [], 'reasons' => array_values($reasons), 'sources' => $documents];
        }
        $ledger = Money::sum($journals->map(fn ($journal) => $this->inventoryAmount($journal->lines))->all());
        $orphans = $movements->whereNotIn('product_variant_id', $stocks->pluck('product_variant_id'))->count();

        return ['rows' => $rows, 'inventory_ledger_minor' => $ledger, 'proven_movements_minor' => $accounted,
            'unexplained_financial_minor' => Money::add($ledger, -$accounted), 'orphan_movements' => $orphans];
    }

    private function inventoryAmount(Collection $lines): int
    {
        return Money::sum($lines->filter(fn ($line) => $line->account?->code === 'inventory')
            ->map(fn ($line) => Money::add((int) $line->debit_minor, -(int) $line->credit_minor))->all());
    }

    private function source(mixed $item, StockMovement $movement): ?array
    {
        $kind = $movement->movement_type;
        $reverse = in_array($kind, ['purchase_reversal', 'sale_reversal', 'sale_return_reversal'], true);
        if ($item instanceof OpeningStockItem && $kind === 'opening_stock') {
            $document = $item->entry;
            $event = 'opening';
            $value = Money::minor((string) $item->line_total);
            $total = $document ? Money::sum($document->items->map(fn ($row) => Money::minor((string) $row->line_total))->all()) : null;
            if (! $document || $total !== Money::minor((string) $document->total_cost)) {
                return null;
            }
            $variantId = $item->product_variant_id;
            $sign = 1;
        } elseif ($item instanceof PurchaseInvoiceItem && in_array($kind, ['purchase', 'purchase_reversal'], true)) {
            $document = $item->invoice;
            $event = 'purchase';
            $value = (int) $item->line_total_minor;
            $total = $document ? (int) $document->items->sum('line_total_minor') : null;
            if (! $document || ! $document->financial_posted_at || $total !== (int) $document->total_minor) {
                return null;
            }
            $variantId = $item->product_variant_id;
            $sign = $reverse ? -1 : 1;
        } elseif ($item instanceof SaleItem && in_array($kind, ['sale', 'sale_reversal'], true)) {
            $document = $item->invoice;
            $event = 'sale';
            $value = $item->cost_minor === null ? null : (int) $item->cost_minor;
            $total = $document ? -(int) $document->items->sum('cost_minor') : null;
            if (! $document || $value === null || -$total !== (int) $document->cost_minor) {
                return null;
            }
            $variantId = $item->product_variant_id;
            $sign = $reverse ? 1 : -1;
        } elseif ($item instanceof SaleReturnItem && in_array($kind, ['sale_return', 'sale_return_reversal'], true) && $item->condition === 'resellable') {
            $document = $item->saleReturn;
            $event = 'return';
            $value = $item->recoverable_minor === null ? null : (int) $item->recoverable_minor;
            $total = $document ? (int) $document->items->where('condition', 'resellable')->sum('cost_minor') : null;
            if (! $document || $value === null || $value !== (int) $item->cost_minor) {
                return null;
            }
            $variantId = $item->saleItem?->product_variant_id;
            $sign = $reverse ? -1 : 1;
        } else {
            return null;
        }

        return ['document' => $document, 'event' => $event, 'reverse' => $reverse, 'document_value' => $reverse ? -$total : $total,
            'variant_id' => (int) $variantId, 'quantity' => $sign * (int) $item->quantity, 'value' => $sign * $value];
    }
}
