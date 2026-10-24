<?php

namespace App\Actions\Purchases;

use App\Models\PurchaseInvoice;
use App\Models\SupplierPayment;
use App\Support\Inventory\OpeningStock\DecimalAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordSupplierPaymentAction
{
    public function execute(PurchaseInvoice $invoice, array $data, int $actorId): SupplierPayment
    {
        return DB::transaction(function () use ($invoice, $data, $actorId): SupplierPayment {
            DB::table('product_code_counters')->where('id', 1)->lockForUpdate()->first();
            $current = PurchaseInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $amount = DecimalAmount::minorUnits((string) $data['amount']);
            $hash = hash('sha256', json_encode([$current->id, $amount, $data['method'], $data['payment_date'], $data['reference'] ?? null, $data['notes'] ?? null], JSON_THROW_ON_ERROR));
            $existing = SupplierPayment::where('client_uuid', $data['client_uuid'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'معرّف الدفعة مستخدم لبيانات أخرى.');

                return $existing;
            }
            abort_unless($current->status === 'approved', 409, 'تُسجل الدفعات للفواتير المعتمدة فقط.');
            if ($amount <= 0 || $amount > $current->total_minor - $current->paid_minor) {
                throw ValidationException::withMessages(['amount' => 'مبلغ الدفعة يجب أن يكون أكبر من صفر وألا يتجاوز المتبقي على الفاتورة.']);
            }
            if ($data['payment_date'] < $current->invoice_date->toDateString()) {
                throw ValidationException::withMessages(['payment_date' => 'تاريخ الدفعة لا يمكن أن يسبق تاريخ الفاتورة.']);
            }
            $payment = SupplierPayment::create(['client_uuid' => $data['client_uuid'], 'request_hash' => $hash,
                'purchase_invoice_id' => $current->id, 'supplier_id' => $current->supplier_id, 'branch_id' => $current->branch_id, 'created_by' => $actorId,
                'amount_minor' => $amount, 'method' => $data['method'], 'payment_date' => $data['payment_date'], 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null]);
            $current->update(['paid_minor' => $current->paid_minor + $amount]);

            return $payment;
        }, 3);
    }
}
