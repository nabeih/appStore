<?php

namespace App\Actions\Finance\Expenses;

use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\DB;

class ExpensePeriodReport
{
    /** @return array{rows:array,expenses_minor:int,cash_paid_minor:int,prepaid_remaining_minor:int,due_allocations:int} */
    public function build(User $actor, int $branchId, string $from, string $to): array
    {
        OperationContext::branch($actor, $branchId, 'finance.view', false);
        abort_if($from > $to, 422, 'تاريخ البداية يتجاوز النهاية.');
        $base = DB::table('journal_lines as lines')->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('financial_accounts as accounts', 'accounts.id', '=', 'lines.financial_account_id')
            ->join('expenses', 'expenses.id', '=', 'lines.expense_id')->where('entries.branch_id', $branchId);
        $rows = (clone $base)->whereBetween('entries.transaction_date', [$from, $to])->select('expenses.category')
            ->selectRaw("SUM(CASE WHEN accounts.code = 'operating_expenses' THEN lines.debit_minor ELSE 0 END) - SUM(CASE WHEN accounts.code = 'operating_expenses' THEN lines.credit_minor ELSE 0 END) AS expenses_minor")
            ->selectRaw("SUM(CASE WHEN accounts.type IN ('cash','bank','wallet') THEN lines.credit_minor ELSE 0 END) - SUM(CASE WHEN accounts.type IN ('cash','bank','wallet') THEN lines.debit_minor ELSE 0 END) AS cash_paid_minor")
            ->groupBy('expenses.category')->orderBy('expenses.category')->get()->map(fn ($row): array => [
                'category' => $row->category, 'expenses_minor' => Money::integer((string) $row->expenses_minor), 'cash_paid_minor' => Money::integer((string) $row->cash_paid_minor)])->all();
        $remaining = (clone $base)->where('entries.transaction_date', '<=', $to)->where('accounts.code', 'prepaid_expenses')
            ->selectRaw('COALESCE(SUM(lines.debit_minor),0) - COALESCE(SUM(lines.credit_minor),0) AS amount')->value('amount');

        return ['rows' => $rows, 'expenses_minor' => Money::sum(array_column($rows, 'expenses_minor')),
            'cash_paid_minor' => Money::sum(array_column($rows, 'cash_paid_minor')), 'prepaid_remaining_minor' => Money::integer((string) $remaining),
            'due_allocations' => RecognizePrepaidExpense::overdue([$branchId], $to)];
    }
}
