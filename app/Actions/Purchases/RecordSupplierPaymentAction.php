<?php

namespace App\Actions\Purchases;

use App\Actions\Finance\FinanceContext;
use App\Actions\Finance\PostJournalAction;
use App\Models\PurchaseInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Sales\OperationContext;

class RecordSupplierPaymentAction
{
    public function execute(PurchaseInvoice $invoice, array $data, int $actorId): SupplierPayment
    {
        return OperationContext::run(function () use ($invoice, $data, $actorId): SupplierPayment {
            $actor = User::findOrFail($actorId);
            $date = FinanceContext::authorize($actor, $invoice->branch_id, 'finance.supplier_pay', $data['payment_date']);
            $current = PurchaseInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $hash = OperationContext::hash([$current->id, $data]);
            if ($existing = SupplierPayment::where('client_uuid', $data['client_uuid'])->first()) {
                return OperationContext::replay($existing, $hash);
            }
            abort_unless($current->status === 'approved', 409, 'تُسجل الدفعات للفواتير المعتمدة فقط.');
            if (! $current->financial_posted_at) {
                FinanceContext::fail('الفاتورة خارج الدفتر الموحد أو غير معتمدة؛ راجع خطة الانتقال دون ترحيل قديم تلقائي.');
            }
            $amount = FinanceContext::amount($data['amount']);
            if ($amount > $current->total_minor - $current->paid_minor || $date < $current->invoice_date->toDateString()) {
                FinanceContext::fail('السداد يتجاوز المتبقي أو يسبق الفاتورة.');
            }
            $account = FinanceContext::account((int) ($data['account_id'] ?? 0), $current->branch_id);
            if ($account->type !== 'cash' && ! ($data['confirmed'] ?? false)) {
                FinanceContext::fail('أكّد تنفيذ التحويل في البنك أو المحفظة.');
            }
            FinanceContext::funds($account, $amount);
            $payment = SupplierPayment::create(['client_uuid' => $data['client_uuid'], 'request_hash' => $hash, 'purchase_invoice_id' => $current->id,
                'supplier_id' => $current->supplier_id, 'branch_id' => $current->branch_id, 'created_by' => $actorId, 'amount_minor' => $amount,
                'method' => $account->type, 'payment_date' => $date, 'financial_account_id' => $account->id, 'status' => 'approved', 'financial_posted_at' => now(),
                'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null]);
            $dimension = ['supplier_id' => $current->supplier_id, 'purchase_invoice_id' => $current->id];
            app(PostJournalAction::class)->execute($payment, 'supplier_payment', $current->branch_id, $actor, $date, [
                ['account' => 'supplier_payables', 'amount' => $amount] + $dimension, ['account' => $account->id, 'amount' => -$amount] + $dimension,
            ], 'سداد مورد '.$current->number);
            $current->increment('paid_minor', $amount);
            OperationContext::audit($payment, $actor, 'supplier_payment.approved', $data['notes'] ?? null, ['amount_minor' => $amount, 'account_id' => $account->id]);

            return $payment;
        });
    }
}
