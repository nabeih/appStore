<?php

namespace App\Actions\Inventory;

use App\Actions\Finance\PostJournalAction;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\OpeningStockEntry;
use App\Models\PurchaseInvoice;
use App\Models\SaleExchange;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Guidance\GuidanceException;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrectDocumentItemAction
{
    public function document(string $kind, int $id): Model
    {
        return match ($kind) {
            'opening' => OpeningStockEntry::findOrFail($id),
            'purchase' => PurchaseInvoice::findOrFail($id),
            default => abort(404),
        };
    }

    public function preview(Model $document, int $itemId, array $data, User $actor): array
    {
        return OperationContext::run(fn (): array => $this->plan($document, $itemId, $data, $actor));
    }

    public function execute(Model $document, int $itemId, array $data, User $actor): AuditEvent
    {
        $this->authorize($document, $actor);

        return OperationContext::run(function () use ($document, $itemId, $data, $actor): AuditEvent {
            DB::table('product_code_counters')->where('id', 1)->lockForUpdate()->first();
            $hash = OperationContext::hash([$document->getMorphClass(), $document->id, $itemId, $data]);
            $existing = AuditEvent::where('action', 'inventory.document_corrected')->where('data->operation_uuid', $data['operation_uuid'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->data['request_hash'], $hash), 409, 'معرّف التصحيح مستخدم لبيانات مختلفة.');

                return $existing;
            }
            abort_unless(! empty($data['confirmed']), 422, 'أكد أن العملية تصحيح إدخال وليست رجوع بضاعة.');
            $plan = $this->plan($document, $itemId, $data, $actor);
            abort_unless(hash_equals($plan['revision'], $data['revision']), 409, 'تغير المستند أو الرصيد بعد المعاينة. أعد المعاينة والتأكيد.');
            $event = OperationContext::audit($document, $actor, 'inventory.document_corrected', $data['reason'],
                $plan + ['request_hash' => $hash, 'operation_uuid' => $data['operation_uuid']]);
            $stock = app(StockLedger::class)->positions($document->branch_id, [$plan['variant_id']], true)->get($plan['variant_id']);
            $dimensions = $document instanceof PurchaseInvoice ? ['supplier_id' => $document->supplier_id, 'purchase_invoice_id' => $document->id] : [];
            app(PostJournalAction::class)->execute($event, 'document_correction', $document->branch_id, $actor, $plan['date'], [
                ['account' => 'inventory', 'amount' => $plan['inventory_delta_minor']],
                ['account' => 'cogs', 'amount' => $plan['cogs_delta_minor']],
                ['account' => $document instanceof PurchaseInvoice ? 'supplier_payables' : 'opening_equity', 'amount' => -$plan['document_delta_minor']] + $dimensions,
            ], 'تصحيح بند '.$document->number.' — '.$data['reason']);
            app(StockLedger::class)->move($stock, $plan['quantity_delta'], $plan['inventory_delta_minor'], 'available', $event, 'document_correction', $actor, $plan['date']);
            $document->touch();

            return $event;
        });
    }

    public function authorize(Model $document, User $actor): void
    {
        OperationContext::branch($actor, $document->branch_id, $document instanceof PurchaseInvoice ? 'purchases.manage' : 'inventory.correct_setup', false);
        abort_unless($actor->can('sales.view_cost') && $actor->can($document instanceof PurchaseInvoice ? 'finance.purchase_post' : 'inventory.valuation_review'), 403);
    }

    private function plan(Model $document, int $itemId, array $data, User $actor): array
    {
        $this->authorize($document, $actor);
        if (($data['classification'] ?? 'entry_error') !== 'entry_error') {
            $this->fail('هذا المسار لتصحيح الإدخال فقط. خروج بضاعة أو بيع خارجي يحتاج مستنده ودليله المحاسبي؛ لا تستخدم تصحيح الكمية بدلًا منه.');
        }
        DB::table('product_code_counters')->where('id', 1)->lockForUpdate()->first();
        $branch = Branch::whereKey($document->branch_id)->lockForUpdate()->firstOrFail();
        $date = OperationContext::date($branch);
        $document = $document->newQuery()->whereKey($document->id)->lockForUpdate()->firstOrFail();
        abort_unless($document->status === 'approved', 422, 'التصحيح للمستند المعتمد فقط.');
        $item = $document->items()->whereKey($itemId)->lockForUpdate()->firstOrFail();
        $corrections = AuditEvent::where('subject_type', $document->getMorphClass())->where('subject_id', $document->id)->where('action', 'inventory.document_corrected')->where('data->item_id', $itemId)->orderBy('id')->get();
        $stock = app(StockLedger::class)->positions($branch->id, [$item->product_variant_id], true)->get($item->product_variant_id);
        if (! $stock || (int) $stock->reserved_quantity > 0 || (int) $stock->quarantined_quantity > 0 || (int) $stock->damaged_quantity > 0) {
            $this->fail('الرصيد مفقود أو توجد حجوزات أو بضاعة قيد الفحص؛ أكمل دورة الطلب أو المرتجع أولًا.');
        }
        $proof = app(ProgressiveStockProof::class)->build($branch->id)['rows'][$item->product_variant_id] ?? null;
        if (! $proof || ! $proof['ready']) {
            throw GuidanceException::forCode('stock.source_unproven', 'correction', 'مصدر الرصيد المالي والمخزني غير مثبت؛ راجع جاهزية الصنف ومستند الشراء أو الافتتاح دون إنشاء قيد تاريخي تلقائي.');
        }
        $history = StockMovement::where('branch_id', $branch->id)->where('product_variant_id', $item->product_variant_id)->orderBy('id')->lockForUpdate()->get();
        $source = $history->first();
        $sourceKind = $document instanceof PurchaseInvoice ? 'purchase' : 'opening_stock';
        if (! $source || $source->source_type !== $item->getMorphClass() || (int) $source->source_id !== (int) $item->id || $source->movement_type !== $sourceKind
            || $history->skip(1)->contains(fn ($movement): bool => ! ($movement->movement_type === 'sale' && $movement->source_type === SaleItem::class)
                && ! ($movement->movement_type === 'document_correction' && $movement->source_type === AuditEvent::class && $corrections->contains('id', $movement->source_id)))) {
            $this->fail('توجد مصادر تكلفة مختلطة أو مرتجعات أو تصحيحات سابقة؛ إعادة توزيع تكلفتها غير مدعومة هنا. رجوع البضاعة الفعلي عبر المرتجع، ولا يُعدّل المستند الأصلي.');
        }
        $sales = SaleItem::whereIn('id', $history->where('movement_type', 'sale')->pluck('source_id'))->with('invoice')->lockForUpdate()->get();
        if (SaleExchange::whereIn('new_invoice_id', $sales->pluck('sale_invoice_id'))->exists()) {
            $this->fail('البيع مرتبط باستبدال؛ تصحيح تكلفته يحتاج مراجعة مستقلة دون تغيير مستندات الاستبدال.');
        }
        $sold = (int) $sales->sum('quantity');
        $quantity = (int) $data['quantity'];
        $cost = Money::minor($data['unit_cost']);
        if ($quantity < $sold || $sales->contains(fn ($sale): bool => $sale->cost_minor === null || $sale->unit_cost === null || (int) $sale->returned_quantity > 0 || $sale->invoice->status !== 'approved')) {
            $this->fail('التصحيح أقل من الكمية المباعة أو يتضمن مرتجعًا أو بيعًا غير معتمد؛ يلزم مراجعة مستقلة.');
        }
        $oldQuantity = (int) ($corrections->last()?->data['quantity'] ?? $item->quantity);
        $originalValue = $document instanceof PurchaseInvoice ? (int) $item->line_total_minor : Money::minor($item->line_total);
        $oldValue = Money::add($originalValue, Money::sum($corrections->map(fn ($event): int => (int) $event->data['document_delta_minor'])->all()));
        $delta = Money::add(Money::multiply($cost, $quantity), -$oldValue);
        $saleDeltas = $sales->mapWithKeys(fn ($sale): array => [$sale->id => Money::multiply($cost, $sale->quantity) - self::saleCost($sale)])->all();
        $cogs = Money::sum(array_values($saleDeltas));
        $inventory = Money::add($delta, -$cogs);
        $newValue = Money::add((int) $stock->value_minor, $inventory);
        $newQuantity = (int) $stock->quantity + $quantity - $oldQuantity;
        if ($newQuantity < 0 || $newValue < 0 || ($newQuantity === 0 && $newValue !== 0)) {
            $this->fail('الأثر لا ينتج رصيدًا صالحًا؛ لم يُحفظ أي جزء.');
        }
        if ($document instanceof PurchaseInvoice && $document->effective_total_minor + $delta < $document->paid_minor) {
            $this->fail('التصحيح يجعل المسدد أكبر من المستحق؛ يلزم مسار رصيد دائن للمورد غير متاح هنا. لن تُغيّر الدفعات.');
        }
        if ($delta === 0 && $quantity === $oldQuantity) {
            $this->fail('لا يوجد فرق يستلزم تصحيحًا.');
        }
        $plan = ['classification' => 'entry_error', 'item_id' => $item->id, 'item_type' => $item->getMorphClass(), 'variant_id' => $item->product_variant_id, 'date' => $date,
            'name' => $item->product_name, 'sku' => $item->sku, 'quantity' => $quantity, 'unit_cost' => Money::format($cost),
            'old_quantity' => $oldQuantity, 'old_value_minor' => $oldValue,
            'quantity_delta' => $quantity - $oldQuantity, 'document_delta_minor' => $delta, 'inventory_delta_minor' => $inventory, 'cogs_delta_minor' => $cogs,
            'payable_delta_minor' => $document instanceof PurchaseInvoice ? $delta : 0, 'sale_cost_deltas_minor' => $saleDeltas,
            'stock_before' => ['quantity' => (int) $stock->quantity, 'value_minor' => (int) $stock->value_minor, 'last_movement_id' => $history->last()->id],
            'stock_after' => ['quantity' => $newQuantity, 'value_minor' => $newValue]];

        return $plan + ['revision' => OperationContext::hash([$document->getMorphClass(), $document->id, $document->version, $actor->id, $plan])];
    }

    public static function saleCost(SaleItem $item): ?int
    {
        if ($item->cost_minor === null) {
            return null;
        }
        $adjustments = AuditEvent::where('action', 'inventory.document_corrected')->where('branch_id', $item->invoice->branch_id)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
            ->get()->sum(fn ($event): int => (int) ($event->data['sale_cost_deltas_minor'][$item->id] ?? 0));

        return Money::add((int) $item->cost_minor, $adjustments);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['correction' => $message]);
    }
}
