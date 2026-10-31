<?php

namespace App\Actions\Purchases;

use App\Actions\Finance\FinanceContext;
use App\Actions\Finance\PostPurchaseFinanceAction;
use App\Actions\Inventory\StockLedger;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\PurchaseInvoice;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Inventory\OpeningStock\DecimalAmount;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovePurchaseInvoiceAction
{
    public function __construct(private PreparePurchaseInvoiceAction $prepare, private RecordSupplierPaymentAction $payments) {}

    public function execute(PurchaseInvoice $invoice, int $version, int $actorId): PurchaseInvoice
    {
        FinanceContext::ready();

        return DB::transaction(function () use ($invoice, $version, $actorId): PurchaseInvoice {
            // Share the lock order used by opening stock and temporary inventory corrections.
            DB::table('product_code_counters')->where('id', 1)->increment('lock_version');
            $current = PurchaseInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'approved') {
                abort_unless($current->approved_revision === $version, 409, 'تم اعتماد نسخة مختلفة من الفاتورة.');

                return $current;
            }
            abort_unless($current->status === 'draft' && $current->version === $version, 409, 'تغيرت الفاتورة أو أُلغيت. حدّث الصفحة ثم راجعها.');
            Branch::whereKey($current->branch_id)->lockForUpdate()->firstOrFail();
            $supplier = Supplier::whereKey($current->supplier_id)->lockForUpdate()->first();
            if (! $supplier) {
                throw ValidationException::withMessages(['supplier_id' => 'المورد مؤرشف. استعده قبل اعتماد الفاتورة.']);
            }
            if ($current->supplier_invoice_number && PurchaseInvoice::where('supplier_id', $supplier->id)->where('supplier_invoice_number', $current->supplier_invoice_number)->where('status', 'approved')->where('id', '!=', $current->id)->exists()) {
                throw ValidationException::withMessages(['supplier_invoice_number' => 'رقم فاتورة المورد مسجل مسبقًا في فاتورة معتمدة.']);
            }
            $items = $current->items()->orderBy('product_variant_id')->lockForUpdate()->get();
            $plan = $this->prepare->execute(['supplier_id' => $current->supplier_id, 'branch_id' => $current->branch_id, 'invoice_date' => $current->invoice_date->toDateString(),
                'initial_payment' => DecimalAmount::format($current->initial_payment_minor), 'payment_method' => $current->payment_method,
                'rows' => $items->map(fn ($item): array => ['product_variant_id' => $item->product_variant_id, 'quantity' => $item->quantity, 'unit_cost' => $item->unit_cost])->all()]);
            $planned = collect($plan['rows'])->keyBy('product_variant_id');
            $inventories = app(StockLedger::class)->positions($current->branch_id, $items->pluck('product_variant_id')->all(), true);
            app(StockLedger::class)->assertDate($current->branch_id, $items->pluck('product_variant_id')->all(), $current->invoice_date->toDateString());
            $updates = $movements = [];
            $timestamp = now();
            foreach ($items as $item) {
                $stock = $inventories->get($item->product_variant_id);
                $oldQuantity = (int) ($stock?->quantity ?? 0);
                $newQuantity = $oldQuantity + $item->quantity;
                if ($newQuantity > 4294967295) {
                    throw ValidationException::withMessages(['rows' => 'الكمية الناتجة تتجاوز الحد المسموح للمخزون.']);
                }
                $oldValue = $stock ? app(StockLedger::class)->value($stock) : 0;
                $newValue = Money::add($oldValue, (int) $item->line_total_minor);
                $average = Money::ratio($newValue, 1, $newQuantity);
                $updates[] = ['branch_id' => $current->branch_id, 'product_variant_id' => $item->product_variant_id, 'quantity' => $newQuantity,
                    'average_cost' => DecimalAmount::format($average), 'value_minor' => $newValue, 'created_at' => $stock?->created_at ?? $timestamp, 'updated_at' => $timestamp];
                $movements[] = ['branch_id' => $current->branch_id, 'product_variant_id' => $item->product_variant_id, 'user_id' => $actorId,
                    'movement_type' => 'purchase', 'quantity_change' => $item->quantity, 'unit_cost' => $item->unit_cost,
                    'value_change_minor' => $item->line_total_minor, 'stock_bucket' => 'available', 'effective_date' => $current->invoice_date->toDateString(),
                    'source_type' => $item->getMorphClass(), 'source_id' => $item->id, 'note' => $current->number, 'created_at' => $timestamp];
                $snapshot = $planned->get($item->product_variant_id);
                foreach (['product_name', 'product_code', 'category_name', 'color_name', 'size_name', 'sku'] as $field) {
                    if ($snapshot[$field] !== $item->$field) {
                        throw ValidationException::withMessages(['rows' => 'تغيرت بيانات صنف منذ حفظ المسودة. افتح التعديل واحفظها لتحديث بياناتها قبل الاعتماد.']);
                    }
                }
            }
            Inventory::upsert($updates, ['branch_id', 'product_variant_id'], ['quantity', 'average_cost', 'value_minor', 'updated_at']);
            StockMovement::insert($movements);
            $current->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => $timestamp, 'approved_revision' => $version,
                'version' => $version + 1, 'supplier_name' => $supplier->name, 'branch_name' => $plan['attributes']['branch_name']]);
            app(PostPurchaseFinanceAction::class)->execute($current, User::findOrFail($actorId));
            if ($current->initial_payment_minor > 0) {
                $this->payments->execute($current, ['client_uuid' => $current->client_uuid, 'amount' => DecimalAmount::format($current->initial_payment_minor),
                    'method' => $current->payment_method, 'payment_date' => OperationContext::today()] + ($current->payment_details ?? []), $actorId);
            }

            return $current->refresh();
        }, 3);
    }
}
