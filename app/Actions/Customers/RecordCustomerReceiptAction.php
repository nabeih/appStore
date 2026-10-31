<?php

namespace App\Actions\Customers;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\PostJournalAction;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\SaleInvoice;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordCustomerReceiptAction
{
    public function __construct(private ManageFinancialAccountsAction $accounts, private PostJournalAction $journal) {}

    public function execute(array $data, User $actor): CustomerReceipt
    {
        return OperationContext::run(function () use ($data, $actor): CustomerReceipt {
            OperationContext::ready();
            $branch = OperationContext::branch($actor, (int) $data['branch_id'], 'sales.collect');
            OperationContext::date($branch, $data['transaction_date'] ?? null);
            $hash = OperationContext::hash($data);
            if ($existing = CustomerReceipt::where('client_uuid', $data['client_uuid'])->first()) {
                return OperationContext::replay($existing, $hash);
            }
            $customer = Customer::findOrFail($data['customer_id']);
            $kind = $data['kind'] ?? 'receipt';
            if ($kind === 'refund') {
                Gate::forUser($actor)->authorize('sales.return');
            }
            $allocations = [];
            $input = $data['allocations'] ?? [];
            $invoices = SaleInvoice::whereIn('id', array_column($input, 'sale_invoice_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($input as $allocation) {
                $invoice = $invoices->get($allocation['sale_invoice_id']);
                $amount = Money::minor($allocation['amount']);
                if (! $invoice || $invoice->customer_id !== $customer->id || $invoice->branch_id !== $branch->id
                    || $invoice->status !== 'approved' || $amount <= 0 || $amount > $invoice->due_minor) {
                    $this->fail('تخصيص التحصيل غير صالح أو يتجاوز مستحق الفاتورة.');
                }
                $allocations[] = ['invoice' => $invoice, 'amount' => $amount];
            }
            if ($kind !== 'refund' && $allocations === []) {
                $this->fail('اختر الفواتير التي تسددها الدفعة وحدد تخصيصها.');
            }
            if ($kind === 'refund' && $allocations !== []) {
                $this->fail('رد الرصيد الدائن لا يسدد فواتير في العملية نفسها.');
            }
            $credits = $this->credits($data['credits'] ?? [], $customer->id, $branch->id);
            $amount = $kind === 'refund' ? array_sum(array_column($credits, 'amount')) : array_sum(array_column($allocations, 'amount'));
            if ($amount <= 0) {
                $this->fail('أدخل مبلغًا موجبًا.');
            }
            if ($kind === 'receipt' && $credits !== []) {
                $this->fail('اختر تسوية الرصيد الدائن بدل تسجيله كقبض جديد.');
            }
            if ($kind !== 'receipt' && array_sum(array_column($credits, 'amount')) !== $amount) {
                $this->fail('تخصيص الرصيد الدائن لا يساوي مبلغ التسوية.');
            }
            $plan = $kind === 'credit' ? ['parts' => [], 'paid' => $amount, 'change' => 0]
                : $this->accounts->payments($data['payments'] ?? [], $branch->id, $amount);
            if ($kind === 'credit' && ! empty($data['payments'])) {
                $this->fail('استخدام الرصيد الدائن ليس قبضًا نقديًا جديدًا.');
            }
            if ($plan['paid'] !== $amount) {
                $this->fail('مجموع حسابات الاستلام يجب أن يساوي المبلغ المخصص.');
            }
            if ($kind === 'refund') {
                $this->accounts->assertFunds($plan['parts']);
            }

            return $this->post($customer->id, $branch->id, $allocations, $plan, $actor, 'collection', null, $kind,
                $credits, $data['client_uuid'], $hash, $data['notes'] ?? null);
        });
    }

    /** @return list<array{return: SaleReturn, amount: int}> */
    public function credits(array $input, ?int $customerId, int $branchId): array
    {
        $returns = SaleReturn::whereIn('id', array_column($input, 'sale_return_id'))
            ->withSum(['creditUsages as used_minor' => fn ($query) => $query->whereHas('receipt', fn ($query) => $query->where('status', 'approved'))], 'amount_minor')
            ->lockForUpdate()->get()->keyBy('id');
        $credits = [];
        $seen = [];
        foreach ($input as $row) {
            $return = $returns->get($row['sale_return_id']);
            $amount = Money::minor($row['amount']);
            if (! $return || isset($seen[$return->id]) || $return->customer_id !== $customerId || $return->branch_id !== $branchId
                || $return->status !== 'approved' || $amount <= 0 || $amount > $return->credit_minor - (int) $return->used_minor) {
                $this->fail('المبلغ يتجاوز الرصيد الدائن المتاح أو يخص عميلًا آخر.');
            }
            $seen[$return->id] = true;
            $credits[] = ['return' => $return, 'amount' => $amount];
        }

        return $credits;
    }

    /** Caller owns the transaction and holds invoice/customer-credit locks.
     * @param  list<array{invoice: SaleInvoice, amount: int}>  $allocations
     * @param  array{parts: array, paid: int, change: int}  $plan
     * @param  list<array{return: SaleReturn, amount: int}>  $credits
     */
    public function post(?int $customerId, int $branchId, array $allocations, array $plan, User $actor, string $origin,
        ?int $originId, string $kind = 'receipt', array $credits = [], ?string $uuid = null, ?string $hash = null, ?string $notes = null): CustomerReceipt
    {
        $receipt = CustomerReceipt::create(['client_uuid' => $uuid ?? (string) Str::uuid(), 'request_hash' => $hash ?? OperationContext::hash([$origin, $originId, $plan]),
            'customer_id' => $customerId, 'branch_id' => $branchId, 'created_by' => $actor->id, 'kind' => $kind, 'status' => 'approved',
            'origin' => $origin, 'origin_id' => $originId, 'transaction_date' => OperationContext::today(), 'amount_minor' => $plan['paid'],
            'change_minor' => $plan['change'], 'notes' => $notes]);
        $lines = [];
        foreach ($plan['parts'] as $part) {
            $clean = $part;
            unset($clean['type']);
            $receipt->parts()->create($clean);
            $lines[] = ['account' => (int) $part['financial_account_id'], 'amount' => ($kind === 'refund' ? -1 : 1) * $part['amount_minor'], 'customer_id' => $customerId];
        }
        foreach ($allocations as $allocation) {
            $invoice = $allocation['invoice'];
            $amount = $allocation['amount'];
            if ($amount <= 0 || $amount > $invoice->due_minor) {
                $this->fail('تغير مستحق الفاتورة. أعد مراجعة التخصيص.');
            }
            $receipt->allocations()->create(['sale_invoice_id' => $invoice->id, 'amount_minor' => $amount]);
            $invoice->increment('paid_minor', $amount);
            $lines[] = ['account' => 'receivables', 'amount' => -$amount, 'customer_id' => $invoice->customer_id, 'sale_invoice_id' => $invoice->id];
        }
        foreach ($credits as $credit) {
            $return = $credit['return'];
            $receipt->creditUsages()->create(['sale_return_id' => $return->id, 'amount_minor' => $credit['amount']]);
            $lines[] = ['account' => 'customer_credit', 'amount' => $credit['amount'], 'customer_id' => $customerId, 'sale_return_id' => $return->id];
        }
        $this->journal->execute($receipt, 'receipt', $branchId, $actor, OperationContext::today(), $lines, $receipt->number);
        OperationContext::audit($receipt, $actor, 'receipt.posted', $notes, ['kind' => $kind, 'allocations' => $receipt->allocations->map->only(['sale_invoice_id', 'amount_minor'])->all()]);

        return $receipt;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['allocations' => $message]);
    }
}
