<?php

namespace App\Actions\Store;

use App\Actions\Inventory\ProgressiveOperation;
use App\Actions\Inventory\StockLedger;
use App\Actions\Sales\PostSaleAction;
use App\Actions\Sales\SaveSaleAction;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\StoreOrder;
use App\Models\StoreOrderEvent;
use App\Models\StoreOrderPayment;
use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;

class ManageStoreOrder
{
    public function transition(StoreOrder $order, array $data, User $actor): StoreOrder
    {
        return OperationContext::run(function () use ($order, $data, $actor): StoreOrder {
            OperationContext::branch($actor, $order->branch_id, 'store.orders.manage');
            $current = app(StoreContext::class)->orders($actor)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $hash = OperationContext::hash([$current->id, $data]);
            if ($event = StoreOrderEvent::where('operation_uuid', $data['operation_uuid'])->first()) {
                abort_unless((int) $event->store_order_id === (int) $current->id && hash_equals($event->request_hash, $hash), 409);

                return $current;
            }
            abort_unless((int) $current->version === (int) $data['version'], 409, 'تغير الطلب؛ حدّث التفاصيل.');
            $from = $current->status;
            $action = $data['action'];
            if ($action === 'confirm') {
                abort_unless($from === 'new', 409, 'التأكيد للطلب الجديد فقط.');
                OperationContext::salesStarted(Branch::findOrFail($current->branch_id));
                $current->load('items');
                $quote = app(StorePricing::class)->quote(['delivery_zone_id' => $current->delivery_zone_id,
                    'rows' => $current->items->map->only(['product_variant_id', 'quantity'])->all()]);
                abort_unless($quote['products_minor'] === (int) $current->products_minor && $quote['delivery_minor'] === (int) $current->delivery_minor
                    && $quote['original_delivery_minor'] === (int) $current->original_delivery_minor, 409, 'تغيرت الأسعار أو الرسوم؛ يلزم طلب محدث وموافقة الزبون، دون تغيير طلبه تلقائيًا.');
                $positions = app(StockLedger::class)->positions($current->branch_id, $current->items->pluck('product_variant_id')->all(), true);
                app(ProgressiveOperation::class)->assertStocks($current->branch_id, $current->items->pluck('product_variant_id')->all());
                foreach ($current->items as $item) {
                    $position = $positions->get($item->product_variant_id);
                    abort_unless($position, 422, 'لا يوجد مخزون لهذا الصنف.');
                    app(StockLedger::class)->cost($position, (int) $item->quantity);
                    $current->reservations()->create(['branch_id' => $current->branch_id, 'product_variant_id' => $item->product_variant_id, 'quantity' => $item->quantity]);
                }
                $customer = Customer::create(['name' => $current->customer_name, 'phone' => $current->phone]);
                $current->update(['status' => 'confirmed', 'confirmed_at' => now(), 'customer_id' => $customer->id, 'delivery_snapshot' => $quote['delivery_snapshot']]);
            } elseif ($action === 'dispatch') {
                abort_unless($from === 'confirmed' && filled($data['courier_name'] ?? null), 422, 'أكد الطلب وحدد مزوّد التوصيل أولًا.');
                abort_unless(isset($current->delivery_snapshot['courier_delivery_minor'], $current->delivery_snapshot['courier_refusal_minor']), 422, 'حدد أجرة المزوّد عند التسليم والرفض قبل إخراج البضاعة.');
                $current->reservations()->whereNull('released_at')->update(['state' => 'in_transit']);
                $current->update(['status' => 'dispatched', 'courier_name' => $data['courier_name'], 'dispatched_at' => now()]);
            } elseif ($action === 'deliver') {
                abort_unless($from === 'dispatched', 409);
                $current->reservations()->whereNull('released_at')->update(['released_at' => now(), 'state' => 'sold']);
                $current->load('items');
                $sale = app(SaveSaleAction::class)->execute(['client_uuid' => $current->sale_uuid, 'branch_id' => $current->branch_id,
                    'customer_id' => $current->customer_id, 'payment_mode' => 'credit', 'payments' => [], 'reason' => 'أسعار طلب الموقع المعتمدة '.$current->id,
                    'below_minimum_confirmed' => (bool) ($data['below_minimum_confirmed'] ?? false),
                    'rows' => $current->items->map(fn ($item): array => ['product_variant_id' => $item->product_variant_id, 'quantity' => $item->quantity,
                        'unit_price' => Money::format($item->unit_price_minor), 'price_reason' => 'سعر الموقع وقت الطلب '.$current->id])->all()], $actor);
                $sale = app(PostSaleAction::class)->execute($sale, $actor);
                app(StoreOrderFinance::class)->recognize($current, false, $actor);
                $current->update(['status' => 'delivered', 'delivered_at' => now(), 'sale_invoice_id' => $sale->id]);
            } elseif ($action === 'refuse') {
                abort_unless($from === 'dispatched', 409);
                app(StoreOrderFinance::class)->recognize($current, true, $actor);
                $current->update(['status' => 'refused']);
            } elseif ($action === 'cancel') {
                abort_unless(in_array($from, ['new', 'confirmed', 'dispatched'], true), 409);
                if ($from === 'dispatched') {
                    $current->update(['status' => 'return_pending']);
                } else {
                    $current->reservations()->whereNull('released_at')->update(['released_at' => now(), 'state' => 'cancelled']);
                    $current->update(['status' => 'cancelled']);
                }
            } elseif ($action === 'return_received') {
                abort_unless(in_array($from, ['refused', 'return_pending'], true) && ! $current->returned_at, 409, 'أكد عودة البضاعة مرة واحدة فقط.');
                $current->reservations()->whereNull('released_at')->update(['released_at' => now(), 'state' => 'returned']);
                $current->update(['returned_at' => now(), 'status' => $from === 'return_pending' ? 'cancelled' : 'refused']);
            } else {
                abort(422);
            }
            $current->increment('version');
            $current->events()->create(['operation_uuid' => $data['operation_uuid'], 'request_hash' => $hash, 'user_id' => $actor->id,
                'from_status' => $from, 'to_status' => $current->status, 'reason' => $data['reason']]);
            OperationContext::audit($current, $actor, 'store_order.'.$action, $data['reason']);

            return $current->refresh();
        });
    }

    public function payment(StoreOrder $order, array $data, User $actor): StoreOrderPayment
    {
        return OperationContext::run(function () use ($order, $data, $actor) {
            OperationContext::branch($actor, $order->branch_id, 'store.orders.manage');
            $current = app(StoreContext::class)->orders($actor)->whereKey($order->id)->lockForUpdate()->firstOrFail();

            return app(StoreOrderFinance::class)->payment($current, $data, $actor);
        });
    }
}
