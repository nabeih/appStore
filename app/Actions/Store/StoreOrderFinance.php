<?php

namespace App\Actions\Store;

use App\Actions\Customers\RecordCustomerReceiptAction;
use App\Actions\Finance\Expenses\ManageExpenseAction;
use App\Actions\Finance\FinanceContext;
use App\Actions\Finance\PostJournalAction;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\StoreOrder;
use App\Models\StoreOrderPayment;
use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class StoreOrderFinance
{
    public const POLICY = 'store_revenue_separate_provider_cost_v1';

    public function provision(): void
    {
        foreach (['delivery_revenue' => 'إيراد التوصيل ورسوم رفض الاستلام', 'courier_receivables' => 'مبالغ محصلة بعهدة مزوّدي التوصيل'] as $code => $name) {
            FinancialAccount::firstOrCreate(['code' => $code], ['name' => $name, 'type' => 'system']);
        }
    }

    public function balances(StoreOrder $order): array
    {
        $order->loadMissing(['invoice', 'payments']);
        $expense = $order->providerExpense()->withSum(['payments as actual_paid' => fn ($q) => $q->where('status', 'approved')], 'amount_minor')->first();
        $providerPaid = (int) ($expense?->actual_paid ?? 0);
        $providerDue = $expense ? ($expense->status === 'approved' ? (int) $expense->amount_minor - $providerPaid : 0)
            : ($order->courier_due_minor === null ? null : ((int) $order->courier_due_minor === 0 ? 0 : null));
        $held = (int) $order->payments->where('kind', 'collect')->where('received_by', 'courier')->sum('amount_minor')
            - (int) $order->payments->where('kind', 'remit')->sum('amount_minor');
        $cashGoods = $order->sale_invoice_id ? (int) DB::table('receipt_allocations as a')
            ->join('customer_receipts as r', 'r.id', '=', 'a.customer_receipt_id')->where('a.sale_invoice_id', $order->sale_invoice_id)
            ->where('r.branch_id', $order->branch_id)->where('r.status', 'approved')->where('r.kind', 'receipt')->sum('a.amount_minor') : 0;

        return ['agreed_total_minor' => Money::add((int) $order->products_minor, (int) $order->delivery_minor),
            'fee_remaining_minor' => (int) $order->fee_due_minor - (int) $order->fee_paid_minor,
            'due_minor' => Money::add((int) ($order->invoice?->due_minor ?? 0), (int) $order->fee_due_minor - (int) $order->fee_paid_minor),
            'provider_paid_minor' => $providerPaid, 'provider_due_minor' => $providerDue, 'provider_expense_status' => $expense?->status,
            'held_minor' => $held, 'collected_minor' => $cashGoods + (int) $order->fee_paid_minor,
            'settlement_status' => $providerDue === null || $providerDue < 0 || $held < 0 || ($expense?->status === 'reversed' && $providerPaid > 0)
                ? 'needs_review' : ($providerDue === 0 && $held === 0 ? 'settled' : 'pending')];
    }

    public function recognize(StoreOrder $order, bool $refused, User $actor): void
    {
        abort_unless(($order->delivery_snapshot['fee_policy'] ?? '') === self::POLICY, 409, 'سياسة هذا الطلب تحتاج معالجًا ماليًا معتمدًا.');
        $date = OperationContext::date(Branch::findOrFail($order->branch_id));
        $fee = $refused ? (int) $order->original_delivery_minor : (int) $order->delivery_minor;
        $cost = $order->delivery_snapshot[$refused ? 'courier_refusal_minor' : 'courier_delivery_minor'] ?? null;
        abort_unless($cost !== null && filled($order->courier_name), 422, 'أجرة مزوّد التوصيل وهويته يجب أن تكونا معلومتين قبل الترحيل.');
        if ($fee > 0) {
            app(PostJournalAction::class)->execute($order, 'delivery_fee', $order->branch_id, $actor, $date, [
                ['account' => 'receivables', 'amount' => $fee, 'customer_id' => $order->customer_id],
                ['account' => 'delivery_revenue', 'amount' => -$fee],
            ], 'رسوم توصيل الطلب '.$order->id.($refused ? ' — رفض استلام دون بيع المنتجات' : ''));
        }
        $expenseId = null;
        if ((int) $cost > 0) {
            $expenses = app(ManageExpenseAction::class);
            $expense = $expenses->save(['client_uuid' => (string) Str::uuid(), 'branch_id' => $order->branch_id, 'title' => 'أجرة مزوّد توصيل الطلب '.$order->id,
                'category' => 'transport', 'payee' => $order->courier_name, 'period_start' => $date, 'period_end' => $date, 'transaction_date' => $date,
                'amount' => Money::format((int) $cost), 'reference' => 'STORE-'.$order->id, 'notes' => 'تكلفة مستقلة عن الرسوم المحصلة من الزبون؛ السياسة '.self::POLICY], $actor);
            $expenseId = $expenses->approve($expense, $actor)->id;
        }
        $order->update(['fee_due_minor' => $fee, 'courier_due_minor' => (int) $cost, 'courier_expense_id' => $expenseId,
            'settlement_status' => (int) $cost > 0 ? 'pending' : 'not_due',
            'collection_status' => $fee + ($refused ? 0 : (int) $order->products_minor) === 0 ? 'paid' : 'uncollected']);
    }

    public function payment(StoreOrder $order, array $data, User $actor): StoreOrderPayment
    {
        $kind = $data['kind'];
        abort_unless(in_array($kind, ['collect', 'remit', 'provider_pay'], true), 422);
        Gate::forUser($actor)->authorize($kind === 'collect' ? 'sales.collect' : 'store.courier_settle');
        if ($kind === 'provider_pay') {
            Gate::forUser($actor)->authorize('finance.expenses.pay');
        }
        $hash = OperationContext::hash([$order->id, $data]);
        if ($old = StoreOrderPayment::where('operation_uuid', $data['operation_uuid'])->first()) {
            abort_unless((int) $old->store_order_id === (int) $order->id && hash_equals($old->request_hash, $hash), 409);

            return $old;
        }
        $amount = Money::minor($data['amount']);
        abort_unless($amount > 0, 422, 'المبلغ يجب أن يكون موجبًا.');
        $date = OperationContext::date(Branch::findOrFail($order->branch_id));
        $account = null;
        if ($kind !== 'collect' || $data['received_by'] === 'store') {
            $account = FinanceContext::account((int) ($data['account_id'] ?? 0), $order->branch_id);
            abort_unless($account->type === $data['method'], 422, 'طريقة الدفع لا تطابق نوع الحساب المختار.');
            abort_unless($account->type === 'cash' || ($data['confirmed'] ?? false), 422, 'أكّد التحويل الفعلي إلى الحساب.');
        } else {
            abort_unless(($data['method'] ?? '') === 'cash', 422, 'التحويل يكون مباشرة لحساب المحل؛ المندوب يستلم نقدًا فقط.');
            $account = FinancialAccount::where('code', 'courier_receivables')->firstOrFail();
        }
        $payment = StoreOrderPayment::create(['store_order_id' => $order->id, 'user_id' => $actor->id, 'operation_uuid' => $data['operation_uuid'],
            'request_hash' => $hash, 'kind' => $kind, 'method' => $data['method'], 'received_by' => $data['received_by'], 'financial_account_id' => $account->id,
            'amount_minor' => $amount, 'reference' => $data['reference'] ?? null]);
        if ($kind === 'collect') {
            Gate::forUser($actor)->authorize('sales.collect');
            abort_unless(in_array($order->status, ['delivered', 'refused'], true), 409, 'التحصيل بعد التسليم أو رفض الاستلام فقط.');
            $invoice = $order->invoice()->lockForUpdate()->first();
            $productDue = $invoice?->due_minor ?? 0;
            $feeDue = (int) $order->fee_due_minor - (int) $order->fee_paid_minor;
            abort_unless($amount <= $productDue + $feeDue, 422, 'المبلغ يتجاوز المستحق.');
            $productPart = min($amount, $productDue);
            $feePart = $amount - $productPart;
            if ($productPart > 0) {
                app(RecordCustomerReceiptAction::class)->post($order->customer_id, $order->branch_id, [['invoice' => $invoice, 'amount' => $productPart]],
                    ['parts' => [['financial_account_id' => $account->id, 'amount_minor' => $productPart, 'tendered_minor' => $productPart, 'change_minor' => 0, 'reference' => $data['reference'] ?? null]],
                        'paid' => $productPart, 'change' => 0], $actor, 'web_order', $payment->id);
            }
            if ($feePart > 0) {
                app(PostJournalAction::class)->execute($payment, 'delivery_collection', $order->branch_id, $actor, $date,
                    [['account' => $account->id, 'amount' => $feePart], ['account' => 'receivables', 'amount' => -$feePart, 'customer_id' => $order->customer_id]], 'قبض رسوم الطلب '.$order->id);
            }
            $order->increment('fee_paid_minor', $feePart);
            $order->increment('collected_minor', $amount);
            $order->update(['collection_status' => $amount === $productDue + $feeDue ? 'paid' : 'partial']);
        } elseif ($kind === 'remit') {
            Gate::forUser($actor)->authorize('store.courier_settle');
            $held = (int) $order->payments()->where('kind', 'collect')->where('received_by', 'courier')->sum('amount_minor')
                - (int) $order->payments()->where('kind', 'remit')->where('id', '!=', $payment->id)->sum('amount_minor');
            abort_unless($amount <= $held, 422, 'التوريد يتجاوز عهدة مزوّد التوصيل.');
            app(PostJournalAction::class)->execute($payment, 'courier_remittance', $order->branch_id, $actor, $date,
                [['account' => $account->id, 'amount' => $amount], ['account' => 'courier_receivables', 'amount' => -$amount]], 'توريد عهدة توصيل الطلب '.$order->id);
        } else {
            Gate::forUser($actor)->authorize('store.courier_settle');
            $expense = $order->providerExpense()->withSum(['payments as actual_paid' => fn ($q) => $q->where('status', 'approved')], 'amount_minor')->lockForUpdate()->first();
            abort_unless($expense && $expense->status === 'approved' && $amount <= (int) $expense->amount_minor - (int) $expense->actual_paid, 422, 'لا يوجد مستحق مزوّد خدمة بهذا المبلغ.');
            app(ManageExpenseAction::class)->pay(Expense::findOrFail($order->courier_expense_id), ['client_uuid' => $data['operation_uuid'], 'amount' => $data['amount'],
                'account_id' => $account->id, 'confirmed' => $data['confirmed'] ?? false, 'reason' => $data['reason'], 'reference' => $data['reference'] ?? null], $actor);
            $order->update(['courier_paid_minor' => (int) $expense->actual_paid + $amount]);
        }
        $live = $order->fresh(['invoice', 'payments']);
        $balances = $this->balances($live);
        $order->update(['courier_paid_minor' => $balances['provider_paid_minor'], 'collected_minor' => $balances['collected_minor'],
            'collection_status' => $live->collection_state, 'settlement_status' => $balances['settlement_status']]);
        OperationContext::audit($payment, $actor, 'store_order.'.$kind, $data['reason'], ['order_id' => $order->id, 'amount_minor' => $amount]);

        return $payment;
    }
}
