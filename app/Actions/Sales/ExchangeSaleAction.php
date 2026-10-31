<?php

namespace App\Actions\Sales;

use App\Actions\Customers\RecordCustomerReceiptAction;
use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Models\SaleExchange;
use App\Models\SaleInvoice;
use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExchangeSaleAction
{
    public function __construct(private ReturnSaleAction $returns, private SaveSaleAction $save, private PostSaleAction $post,
        private RecordCustomerReceiptAction $receipts, private ManageFinancialAccountsAction $accounts, private PrepareSaleAction $prepare) {}

    public function execute(SaleInvoice $invoice, array $data, User $actor): SaleExchange
    {
        return OperationContext::run(function () use ($invoice, $data, $actor): SaleExchange {
            OperationContext::ready();
            $branch = OperationContext::branch($actor, $invoice->branch_id, 'sales.exchange');
            $date = OperationContext::date($branch);
            $hash = OperationContext::hash([$invoice->id, $data]);
            if ($existing = SaleExchange::where('client_uuid', $data['client_uuid'])->first()) {
                return OperationContext::replay($existing, $hash);
            }
            $current = SaleInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $exchange = SaleExchange::create(['client_uuid' => $data['client_uuid'], 'request_hash' => $hash, 'original_invoice_id' => $current->id,
                'branch_id' => $branch->id, 'created_by' => $actor->id, 'approved_by' => $actor->id, 'transaction_date' => $date, 'reason' => $data['reason']]);
            $returnData = ['client_uuid' => (string) Str::uuid(), 'rows' => $data['return_rows'], 'reason' => $data['reason'],
                'customer_id' => $current->customer_id ?? ($data['customer_id'] ?? null), 'refund_payments' => [], 'kind' => 'exchange'];
            $return = $this->returns->execute($current, $returnData, $actor, true);
            $customerId = $return->customer_id;
            $newData = ['client_uuid' => (string) Str::uuid(), 'branch_id' => $branch->id, 'customer_id' => $customerId, 'rows' => $data['rows'],
                'discount' => $data['discount'] ?? [], 'reason' => $data['reason'], 'payment_mode' => 'credit', 'payments' => []];
            $new = $this->save->execute($newData, $actor);
            $new = $this->post->execute($new, $actor, true);
            $exchange->update(['new_invoice_id' => $new->id, 'sale_return_id' => $return->id]);
            $availableCredit = (int) $return->credit_minor;
            $use = min($availableCredit, $new->due_minor);
            if ($use > 0) {
                $this->receipts->post($customerId, $branch->id, [['invoice' => $new, 'amount' => $use]],
                    ['parts' => [], 'paid' => $use, 'change' => 0], $actor, 'exchange', $exchange->id, 'credit', [['return' => $return, 'amount' => $use]]);
            }
            $current->refresh();
            $new->refresh();
            $fullDue = $current->due_minor + $new->due_minor;
            $paymentPlan = $this->accounts->payments($data['payments'] ?? [], $branch->id, $fullDue, true);
            $remaining = $paymentPlan['paid'];
            $allocations = [];
            foreach ([$current, $new] as $target) {
                $allocated = min($target->due_minor, $remaining);
                if ($allocated > 0) {
                    $allocations[] = ['invoice' => $target, 'amount' => $allocated];
                    $remaining -= $allocated;
                }
            }
            if ($paymentPlan['paid'] > 0) {
                $this->receipts->post($customerId, $branch->id, $allocations, $paymentPlan, $actor, 'exchange', $exchange->id);
            }
            $leftCredit = $availableCredit - $use;
            $refund = $this->accounts->payments($data['refund_payments'] ?? [], $branch->id, $leftCredit);
            if ($refund['paid'] > 0) {
                $this->accounts->assertFunds($refund['parts']);
                $this->receipts->post($customerId, $branch->id, [], $refund, $actor, 'exchange', $exchange->id, 'refund', [['return' => $return, 'amount' => $refund['paid']]]);
                $return->increment('refund_minor', $refund['paid']);
            }
            $current->refresh();
            $new->refresh();
            if (! $customerId && ($current->due_minor + $new->due_minor > 0 || $leftCredit > $refund['paid'])) {
                throw ValidationException::withMessages(['customer_id' => 'المركز الكامل للاستبدال يتضمن دينًا أو مبلغًا لصالح العميل. اختر عميلًا معرفًا أو سوِّ المبالغ بالكامل.']);
            }
            if (($data['settlement_mode'] ?? 'paid') === 'paid' && $current->due_minor + $new->due_minor > 0) {
                throw ValidationException::withMessages(['payments' => 'المقبوض لا يسدد المركز الكامل بعد الاستبدال، بما فيه الدين السابق. اختر الاحتفاظ بالمستحق صراحةً أو أكمل القبض.']);
            }
            OperationContext::audit($exchange, $actor, 'exchange.approved', $data['reason'], ['old_due_minor' => $current->due_minor,
                'new_due_minor' => $new->due_minor, 'credit_used_minor' => $use, 'received_minor' => $paymentPlan['paid'], 'refunded_minor' => $refund['paid']]);

            return $exchange;
        });
    }

    public function preview(SaleInvoice $invoice, array $data, User $actor): array
    {
        OperationContext::branch($actor, $invoice->branch_id, 'sales.exchange');
        $returnPlan = $this->returns->prepare($invoice, ['rows' => $data['return_rows'], 'refund_payments' => [],
            'customer_id' => $invoice->customer_id ?? ($data['customer_id'] ?? null)], $actor, true);
        $newPlan = $this->prepare->execute(['branch_id' => $invoice->branch_id, 'customer_id' => $returnPlan['customer_id'],
            'rows' => $data['rows'], 'discount' => $data['discount'] ?? [], 'reason' => $data['reason'], 'payments' => [], 'payment_mode' => 'credit'], $actor, false, false, $returnPlan['rows']);
        $creditUsed = min($returnPlan['credit'], $newPlan['attributes']['total_minor']);
        $fullDue = $returnPlan['due_after'] + $newPlan['attributes']['total_minor'] - $creditUsed;
        $payments = $this->accounts->payments($data['payments'] ?? [], $invoice->branch_id, $fullDue, true);
        $creditLeft = $returnPlan['credit'] - $creditUsed;
        $refund = $this->accounts->payments($data['refund_payments'] ?? [], $invoice->branch_id, $creditLeft);
        $result = ['return_total' => Money::format($returnPlan['total']), 'old_debt_after_return' => Money::format($returnPlan['due_after']),
            'new_sale_total' => Money::format($newPlan['attributes']['total_minor']), 'credit_applied' => Money::format($creditUsed),
            'full_due_before_payment' => Money::format($fullDue), 'received' => Money::format($payments['paid']), 'change' => Money::format($payments['change']),
            'remaining_due' => Money::format($fullDue - $payments['paid']), 'refunded' => Money::format($refund['paid']), 'remaining_credit' => Money::format($creditLeft - $refund['paid'])];
        if (SalesAccess::allows($actor, 'sales.view_cost')) {
            $result['gross_profit_change'] = Money::format($newPlan['attributes']['total_minor'] - $newPlan['attributes']['cost_minor'] - $returnPlan['total'] + $returnPlan['cost']);
        }

        return $result;
    }
}
