<?php

namespace App\Actions\Sales;

use App\Actions\Customers\RecordCustomerReceiptAction;
use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Inventory\StockLedger;
use App\Models\Customer;
use App\Models\SaleInvoice;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Validation\ValidationException;

class ReturnSaleAction
{
    public function __construct(private StockLedger $stock, private ManageFinancialAccountsAction $accounts, private PostJournalAction $journal) {}

    public function prepare(SaleInvoice $invoice, array $data, User $actor, bool $exchange = false): array
    {
        $branch = OperationContext::branch($actor, $invoice->branch_id, $exchange ? 'sales.exchange' : 'sales.return');
        $date = OperationContext::date($branch, $data['transaction_date'] ?? null);
        abort_unless($invoice->status === 'approved', 409, 'المرتجع يحتاج فاتورة بيع معتمدة.');
        $items = $invoice->items()->orderBy('id')->get()->keyBy('id');
        $rows = [];
        $seen = [];
        $total = $cost = 0;
        foreach ($data['rows'] as $input) {
            $item = $items->get($input['sale_item_id']);
            $quantity = (int) $input['quantity'];
            if (! $item || isset($seen[$item->id]) || $quantity <= 0 || $quantity > $item->quantity - $item->returned_quantity) {
                $this->fail('الكمية تتجاوز المتبقي القابل للإرجاع، أو أن السطر لا يخص الفاتورة.');
            }
            $seen[$item->id] = true;
            if ($item->cost_minor === null || $item->unit_cost === null) {
                $this->fail('التكلفة التاريخية مفقودة لهذا السطر. يلزم مراجعتها؛ لن تستخدم تكلفة اليوم.');
            }
            $before = (int) $item->returned_quantity;
            $after = $before + $quantity;
            $net = Money::ratio((int) $item->net_minor, $after, $item->quantity) - Money::ratio((int) $item->net_minor, $before, $item->quantity);
            $rowCost = Money::ratio((int) $item->cost_minor, $after, $item->quantity) - Money::ratio((int) $item->cost_minor, $before, $item->quantity);
            $condition = $input['condition'];
            if (! in_array($condition, ['resellable', 'inspection', 'damaged'], true)) {
                $this->fail('حدد حالة البضاعة المرتجعة.');
            }
            $rows[] = ['item' => $item, 'quantity' => $quantity, 'condition' => $condition, 'net_minor' => $net,
                'cost_minor' => $rowCost, 'recoverable_minor' => $condition === 'damaged' ? 0 : $rowCost];
            $total += $net;
            $cost += $rowCost;
        }
        if ($rows === []) {
            $this->fail('اختر قطعة واحدة على الأقل للإرجاع.');
        }
        $customerId = $invoice->customer_id ?? ($data['customer_id'] ?? null);
        if ($customerId) {
            $customerId = Customer::findOrFail($customerId)->id;
        }
        $debt = min($invoice->due_minor, $total);
        $credit = $total - $debt;
        $refund = $this->accounts->payments($data['refund_payments'] ?? [], $invoice->branch_id, $credit);
        if (! $exchange && ! $customerId && $credit > $refund['paid']) {
            $this->fail('للاحتفاظ بمبلغ لصالح العميل اختر عميلًا معرفًا، أو أعد له كامل المبلغ المستحق.');
        }

        return ['rows' => $rows, 'total' => $total, 'cost' => $cost, 'debt' => $debt, 'credit' => $credit, 'refund' => $refund,
            'customer_id' => $customerId, 'date' => $date, 'due_after' => $invoice->due_minor - $debt];
    }

    public function execute(SaleInvoice $invoice, array $data, User $actor, bool $exchange = false): SaleReturn
    {
        return OperationContext::run(function () use ($invoice, $data, $actor, $exchange): SaleReturn {
            OperationContext::ready();
            OperationContext::branch($actor, $invoice->branch_id, $exchange ? 'sales.exchange' : 'sales.return');
            $hash = OperationContext::hash([$invoice->id, $data]);
            if ($existing = SaleReturn::where('client_uuid', $data['client_uuid'])->first()) {
                return OperationContext::replay($existing, $hash);
            }
            $current = SaleInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $plan = $this->prepare($current, $data, $actor, $exchange);
            $this->accounts->assertFunds($plan['refund']['parts']);
            $return = SaleReturn::create(['client_uuid' => $data['client_uuid'], 'request_hash' => $hash, 'sale_invoice_id' => $current->id,
                'customer_id' => $plan['customer_id'], 'branch_id' => $current->branch_id, 'created_by' => $actor->id, 'approved_by' => $actor->id, 'status' => 'approved',
                'kind' => $data['kind'] ?? 'return', 'transaction_date' => $plan['date'], 'total_minor' => $plan['total'],
                'cost_minor' => $plan['cost'], 'debt_reduction_minor' => $plan['debt'], 'refund_minor' => $plan['refund']['paid'],
                'credit_minor' => $plan['credit'], 'reason' => $data['reason']]);
            $positions = $this->stock->positions($current->branch_id, array_map(fn (array $row): int => $row['item']->product_variant_id, $plan['rows']), true);
            $this->stock->assertDate($current->branch_id, $positions->keys()->all(), $plan['date']);
            $lines = [];
            $dimensions = ['customer_id' => $return->customer_id, 'sale_invoice_id' => $current->id, 'sale_return_id' => $return->id];
            foreach ($plan['rows'] as $row) {
                $item = $row['item'];
                $attributes = $row;
                unset($attributes['item']);
                $returnItem = $return->items()->create($attributes + ['sale_item_id' => $item->id]);
                $position = $positions->get($item->product_variant_id);
                if (! $position) {
                    $this->fail('سجل المخزون الأصلي مفقود. راجع بيانات الصنف قبل المرتجع.');
                }
                $bucket = match ($row['condition']) {
                    'resellable' => 'available', 'inspection' => 'quarantine', 'damaged' => 'damaged'
                };
                $this->stock->move($position, $row['quantity'], $row['recoverable_minor'], $bucket, $returnItem, 'sale_return', $actor, $plan['date']);
                $item->increment('returned_quantity', $row['quantity']);
                $account = match ($row['condition']) {
                    'resellable' => 'inventory', 'inspection' => 'quarantine', 'damaged' => 'inventory_loss'
                };
                $lines[] = ['account' => $account, 'amount' => $row['cost_minor']] + $dimensions;
            }
            $lines[] = ['account' => 'cogs', 'amount' => -$plan['cost']] + $dimensions;
            $lines[] = ['account' => 'sales_returns', 'amount' => $plan['total']] + $dimensions;
            $lines[] = ['account' => 'receivables', 'amount' => -$plan['debt'], 'customer_id' => $current->customer_id, 'sale_invoice_id' => $current->id];
            $lines[] = ['account' => 'customer_credit', 'amount' => -$plan['credit']] + $dimensions;
            $this->journal->execute($return, 'return', $current->branch_id, $actor, $plan['date'], $lines, $return->number);
            $current->increment('returned_minor', $plan['total']);
            $current->increment('released_minor', $plan['credit']);
            if ($plan['refund']['paid'] > 0) {
                app(RecordCustomerReceiptAction::class)->post($return->customer_id, $current->branch_id, [], $plan['refund'], $actor, 'return', $return->id,
                    'refund', [['return' => $return, 'amount' => $plan['refund']['paid']]]);
            }
            OperationContext::audit($return, $actor, 'return.approved', $data['reason'], ['debt_reduction_minor' => $plan['debt'], 'credit_created_minor' => $plan['credit']]);

            return $return;
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['rows' => $message]);
    }
}
