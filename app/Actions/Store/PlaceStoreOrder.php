<?php

namespace App\Actions\Store;

use App\Actions\Notifications\DispatchAppAlerts;
use App\Models\StoreOrder;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlaceStoreOrder
{
    public function execute(array $data): StoreOrder
    {
        return OperationContext::run(function () use ($data): StoreOrder {
            $hash = OperationContext::hash($data);
            if ($existing = StoreOrder::where('client_uuid', $data['client_uuid'])->lockForUpdate()->first()) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'تعذر إعادة الطلب ببيانات مختلفة.');

                return $existing;
            }
            $settings = app(StoreContext::class)->checkoutSettings();
            if (! hash_equals(hash('sha256', $settings->policy), $data['policy_version'])) {
                throw ValidationException::withMessages(['policy_version' => 'تغيرت سياسة الطلب؛ راجعها وأعد الموافقة.']);
            }
            $quote = app(StorePricing::class)->quote($data);
            if (! hash_equals(app(StorePricing::class)->revision($quote), $data['quote_revision'] ?? '')) {
                throw ValidationException::withMessages(['quote_revision' => 'تغيرت الأسعار أو الرسوم منذ المعاينة؛ حدّث الإجمالي وراجع موافقتك قبل إرسال الطلب.']);
            }
            $rows = $quote['rows'];
            unset($quote['rows']);
            $order = StoreOrder::create($quote + ['client_uuid' => $data['client_uuid'], 'request_hash' => $hash, 'sale_uuid' => (string) Str::uuid(),
                'delivery_zone_id' => $data['delivery_zone_id'], 'customer_name' => trim($data['customer_name']), 'phone' => trim($data['phone']),
                'address' => trim($data['address']), 'notes' => $data['notes'] ?? null, 'accepted_policy' => $settings->policy,
                'policy_version' => $data['policy_version'], 'policy_accepted_at' => now()]);
            $order->items()->createMany($rows);
            $order->events()->create(['operation_uuid' => (string) Str::uuid(), 'request_hash' => $hash, 'to_status' => 'new']);
            app(DispatchAppAlerts::class)->record('store-order:'.$order->id, 'store_order', $order->branch_id, $order->id, 'طلب موقع جديد', 'وصل طلب جديد رقم '.$order->id.' وينتظر المراجعة.');

            return $order;
        });
    }
}
