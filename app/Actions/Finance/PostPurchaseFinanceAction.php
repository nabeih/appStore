<?php

namespace App\Actions\Finance;

use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Support\Sales\OperationContext;

class PostPurchaseFinanceAction
{
    public function execute(PurchaseInvoice $invoice, User $actor): void
    {
        FinanceContext::authorize($actor, $invoice->branch_id, 'finance.purchase_post', $invoice->invoice_date->toDateString(), false);
        if ($invoice->financial_posted_at) {
            return;
        }
        $dimension = ['supplier_id' => $invoice->supplier_id, 'purchase_invoice_id' => $invoice->id];
        app(PostJournalAction::class)->execute($invoice, 'purchase', $invoice->branch_id, $actor, $invoice->invoice_date->toDateString(), [
            ['account' => 'inventory', 'amount' => (int) $invoice->total_minor] + $dimension,
            ['account' => 'supplier_payables', 'amount' => -(int) $invoice->total_minor] + $dimension,
        ], 'اعتماد شراء '.$invoice->number);
        $invoice->update(['financial_posted_at' => now()]);
        OperationContext::audit($invoice, $actor, 'purchase.finance_posted', null, ['amount_minor' => $invoice->total_minor]);
    }
}
