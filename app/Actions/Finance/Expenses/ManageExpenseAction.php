<?php

namespace App\Actions\Finance\Expenses;

use App\Actions\Finance\FinanceContext;
use App\Actions\Finance\PostJournalAction;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Carbon\CarbonImmutable;

class ManageExpenseAction
{
    public function save(array $data, User $actor): Expense
    {
        return OperationContext::run(function () use ($data, $actor): Expense {
            FinanceContext::authorize($actor, (int) $data['branch_id'], 'finance.expenses.manage', $data['transaction_date'], false);
            $hash = OperationContext::hash($data);
            if ($existing = Expense::where('client_uuid', $data['client_uuid'])->first()) {
                return OperationContext::replay($existing, $hash);
            }
            $start = CarbonImmutable::parse($data['period_start']);
            $end = CarbonImmutable::parse($data['period_end']);
            if ($start > $end || $start->format('Y-m') !== $end->format('Y-m') || $data['transaction_date'] < $data['period_start'] || $data['transaction_date'] > $data['period_end']) {
                FinanceContext::fail('هذه الشاشة لمصروف فترة واحدة. المصروف المقدم متعدد الأشهر يحتاج جدول توزيع؛ لا تسجله كاملًا في شهر واحد.');
            }
            if (! in_array($data['category'], ['rent', 'utilities', 'maintenance', 'transport', 'supplies', 'other'], true)) {
                FinanceContext::fail('الرواتب ومصادر الوحدات الأخرى لا تُسجل هنا يدويًا.');
            }
            $expense = Expense::create(['client_uuid' => $data['client_uuid'], 'request_hash' => $hash, 'branch_id' => $data['branch_id'], 'created_by' => $actor->id,
                'title' => trim($data['title']), 'category' => $data['category'], 'payee' => $data['payee'] ?? null, 'period_start' => $data['period_start'], 'period_end' => $data['period_end'],
                'transaction_date' => $data['transaction_date'], 'amount_minor' => FinanceContext::amount($data['amount']), 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null]);
            OperationContext::audit($expense, $actor, 'expense.drafted');

            return $expense;
        });
    }

    public function approve(Expense $expense, User $actor): Expense
    {
        return OperationContext::run(function () use ($expense, $actor): Expense {
            $date = FinanceContext::authorize($actor, $expense->branch_id, 'finance.expenses.approve', $expense->transaction_date->toDateString(), false);
            $current = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'approved') {
                return $current;
            }
            abort_unless($current->status === 'draft', 409, 'هذا المستند غير قابل للاعتماد.');
            $dimension = ['expense_id' => $current->id];
            app(PostJournalAction::class)->execute($current, 'expense', $current->branch_id, $actor, $date, [
                ['account' => 'operating_expenses', 'amount' => (int) $current->amount_minor] + $dimension,
                ['account' => 'expense_payables', 'amount' => -(int) $current->amount_minor] + $dimension], 'إثبات مصروف '.$current->number);
            $current->update(['status' => 'approved', 'approved_by' => $actor->id, 'posted_at' => now()]);
            OperationContext::audit($current, $actor, 'expense.approved');

            return $current;
        });
    }

    public function pay(Expense $expense, array $data, User $actor): ExpensePayment
    {
        return OperationContext::run(function () use ($expense, $data, $actor): ExpensePayment {
            $date = FinanceContext::authorize($actor, $expense->branch_id, 'finance.expenses.pay', $data['transaction_date'] ?? null);
            $current = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $hash = OperationContext::hash([$current->id, $data]);
            if ($existing = ExpensePayment::where('client_uuid', $data['client_uuid'])->first()) {
                return OperationContext::replay($existing, $hash);
            }
            abort_unless($current->status === 'approved', 409, 'اعتمد المصروف قبل صرف دفعة.');
            $paid = (int) $current->payments()->where('status', 'approved')->sum('amount_minor');
            $amount = FinanceContext::amount($data['amount']);
            if ($amount > $current->amount_minor - $paid || $date < $current->transaction_date->toDateString()) {
                FinanceContext::fail('الدفعة تتجاوز المستحق أو تسبق إثبات المصروف.');
            }
            $account = FinanceContext::account((int) $data['account_id'], $current->branch_id);
            if ($account->type !== 'cash' && ! ($data['confirmed'] ?? false)) {
                FinanceContext::fail('أكّد تنفيذ التحويل الفعلي.');
            }
            FinanceContext::funds($account, $amount);
            $payment = ExpensePayment::create(['client_uuid' => $data['client_uuid'], 'request_hash' => $hash, 'expense_id' => $current->id, 'branch_id' => $current->branch_id,
                'financial_account_id' => $account->id, 'created_by' => $actor->id, 'transaction_date' => $date, 'amount_minor' => $amount, 'reference' => $data['reference'] ?? null, 'reason' => $data['reason']]);
            $dimension = ['expense_id' => $current->id];
            app(PostJournalAction::class)->execute($payment, 'expense_payment', $current->branch_id, $actor, $date, [
                ['account' => 'expense_payables', 'amount' => $amount] + $dimension, ['account' => $account->id, 'amount' => -$amount] + $dimension], 'سند صرف '.$payment->number);
            OperationContext::audit($payment, $actor, 'expense_payment.approved', $data['reason']);

            return $payment;
        });
    }

    public function reversePayment(ExpensePayment $payment, User $actor, string $reason): ExpensePayment
    {
        return OperationContext::run(function () use ($payment, $actor, $reason): ExpensePayment {
            $date = FinanceContext::authorize($actor, $payment->branch_id, 'finance.reverse');
            if (mb_strlen(trim($reason)) < 3) {
                FinanceContext::fail('سبب العكس مطلوب.');
            }
            $current = ExpensePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'reversed') {
                return $current;
            }
            Expense::whereKey($current->expense_id)->lockForUpdate()->firstOrFail();
            $entry = JournalEntry::where('source_type', $current->getMorphClass())->where('source_id', $current->id)->where('event', 'expense_payment')->firstOrFail();
            app(PostJournalAction::class)->reverse($entry, $actor, $reason, $date);
            $current->update(['status' => 'reversed', 'reversed_at' => now()]);
            OperationContext::audit($current, $actor, 'expense_payment.reversed', $reason);

            return $current;
        });
    }

    public function reverse(Expense $expense, User $actor, string $reason): Expense
    {
        return OperationContext::run(function () use ($expense, $actor, $reason): Expense {
            $date = FinanceContext::authorize($actor, $expense->branch_id, 'finance.reverse');
            if (mb_strlen(trim($reason)) < 3) {
                FinanceContext::fail('سبب العكس مطلوب.');
            }
            $current = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            if (in_array($current->status, ['reversed', 'cancelled'], true)) {
                return $current;
            }
            if ($current->payments()->where('status', 'approved')->exists()) {
                FinanceContext::fail('اعكس سندات الصرف التابعة أولًا.');
            }
            if ($current->status === 'approved') {
                $entry = JournalEntry::where('source_type', $current->getMorphClass())->where('source_id', $current->id)->where('event', 'expense')->firstOrFail();
                app(PostJournalAction::class)->reverse($entry, $actor, $reason, $date);
            }
            $current->update(['status' => $current->status === 'draft' ? 'cancelled' : 'reversed', 'reversed_at' => now()]);
            OperationContext::audit($current, $actor, 'expense.reversed', $reason);

            return $current;
        });
    }
}
