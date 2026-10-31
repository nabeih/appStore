<?php

namespace App\Actions\Finance;

use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Sales\OperationContext;

class ReverseSupplierPaymentAction
{
    public function execute(SupplierPayment $payment, User $actor, string $reason): SupplierPayment
    {
        return OperationContext::run(function () use ($payment, $actor, $reason): SupplierPayment {
            $date = FinanceContext::authorize($actor, $payment->branch_id, 'finance.reverse');
            if (mb_strlen(trim($reason)) < 3) {
                FinanceContext::fail('سبب العكس مطلوب.');
            }
            $current = SupplierPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'reversed') {
                return $current;
            }
            if (! $current->financial_posted_at) {
                FinanceContext::fail('الدفعة القديمة تحتاج مراجعة الانتقال؛ لا تعكس ماليًا بالافتراض.');
            }
            $invoice = PurchaseInvoice::whereKey($current->purchase_invoice_id)->lockForUpdate()->firstOrFail();
            $entry = JournalEntry::where('source_type', $current->getMorphClass())->where('source_id', $current->id)->where('event', 'supplier_payment')->firstOrFail();
            app(PostJournalAction::class)->reverse($entry, $actor, $reason, $date);
            $invoice->decrement('paid_minor', $current->amount_minor);
            $current->update(['status' => 'reversed', 'reversed_at' => now()]);
            OperationContext::audit($current, $actor, 'supplier_payment.reversed', $reason);

            return $current;
        });
    }
}
