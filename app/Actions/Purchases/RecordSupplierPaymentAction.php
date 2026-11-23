<?php

namespace App\Actions\Purchases;

use App\Actions\Finance\FinanceContext;
use App\Actions\Finance\ManageSupplierDebtSettlement;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Inventory\ProgressiveOperation;
use App\Models\Branch;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierDebtSettlement;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Sales\OperationContext;

class RecordSupplierPaymentAction
{
    public function execute(PurchaseInvoice|SupplierDebtSettlement $invoice, array $data, int $actorId): SupplierPayment
    {
        return OperationContext::run(function () use ($invoice, $data, $actorId): SupplierPayment {
            $actor = User::findOrFail($actorId);
            $date = FinanceContext::authorize($actor, $invoice->branch_id, 'finance.supplier_pay', $data['payment_date']);
            $current = $invoice->newQuery()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $settlement = $current instanceof SupplierDebtSettlement;
            if (! $settlement) {
                $current->load(['corrections' => fn ($query) => $query->lockForUpdate()]);
            }
            $hash = OperationContext::hash($settlement ? ['supplier_debt_settlement', $current->id, $data] : [$current->id, $data]);
            if ($existing = SupplierPayment::where('client_uuid', $data['client_uuid'])->first()) {
                return OperationContext::replay($existing, $hash);
            }
            abort_unless($current->status === 'approved', 409, $settlement ? 'السداد متاح لتسوية مديونية معتمدة فقط؛ المسودة والمعكوسة لا تُسدّدان.' : 'تُسجل الدفعات للفواتير المعتمدة فقط.');
            if ($settlement) {
                OperationContext::date(Branch::whereKey($current->branch_id)->lockForUpdate()->firstOrFail(), $date);
                if (! Supplier::whereKey($current->supplier_id)->lockForUpdate()->exists()) {
                    FinanceContext::fail('المورد مؤرشف؛ استعده من صفحة المورد قبل السداد.');
                }
                app(ManageSupplierDebtSettlement::class)->assertFinancialSupport($current);
                if ((int) $current->payments()->where('status', 'approved')->sum('amount_minor') !== $current->paid_minor) {
                    FinanceContext::fail('سندات سداد التسوية لا تطابق الرصيد المسدد؛ راجع كشف المورد.');
                }
            } else {
                app(ProgressiveOperation::class)->assertParty($current->branch_id, 'supplier', $current->supplier_id);
            }
            if (! $current->financial_posted_at) {
                FinanceContext::fail('الفاتورة خارج الدفتر الموحد أو غير معتمدة؛ راجع خطة الانتقال دون ترحيل قديم تلقائي.');
            }
            $amount = FinanceContext::amount($data['amount']);
            $total = $settlement ? $current->amount_minor : $current->effective_total_minor;
            $sourceDate = $settlement ? $current->posting_date : $current->invoice_date;
            if ($amount > $total - $current->paid_minor || $date < $sourceDate->toDateString()) {
                FinanceContext::fail('السداد يتجاوز المتبقي أو يسبق الفاتورة.');
            }
            $account = FinanceContext::account((int) ($data['account_id'] ?? 0), $current->branch_id);
            if ($account->type !== 'cash' && ! ($data['confirmed'] ?? false)) {
                FinanceContext::fail('أكّد تنفيذ التحويل في البنك أو المحفظة.');
            }
            FinanceContext::funds($account, $amount);
            $source = $settlement ? ['purchase_invoice_id' => null, 'supplier_debt_settlement_id' => $current->id] : ['purchase_invoice_id' => $current->id];
            $payment = SupplierPayment::create($source + ['client_uuid' => $data['client_uuid'], 'request_hash' => $hash,
                'supplier_id' => $current->supplier_id, 'branch_id' => $current->branch_id, 'created_by' => $actorId, 'amount_minor' => $amount,
                'method' => $account->type, 'payment_date' => $date, 'financial_account_id' => $account->id, 'status' => 'approved', 'financial_posted_at' => now(),
                'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null]);
            $dimension = ['supplier_id' => $current->supplier_id, 'purchase_invoice_id' => $settlement ? null : $current->id];
            app(PostJournalAction::class)->execute($payment, 'supplier_payment', $current->branch_id, $actor, $date, [
                ['account' => 'supplier_payables', 'amount' => $amount] + $dimension, ['account' => $account->id, 'amount' => -$amount] + $dimension,
            ], 'سداد مورد '.$current->number);
            $current->increment('paid_minor', $amount);
            OperationContext::audit($payment, $actor, 'supplier_payment.approved', $data['notes'] ?? null, ['amount_minor' => $amount, 'account_id' => $account->id]);

            return $payment;
        });
    }
}
