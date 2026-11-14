<?php

namespace App\Actions\Finance\Expenses;

use App\Actions\Finance\FinanceContext;
use App\Actions\Finance\PostJournalAction;
use App\Models\Expense;
use App\Models\ExpenseAllocation;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

class RecognizePrepaidExpense
{
    public static function installed(): bool
    {
        return Schema::hasTable('expense_allocations') && Schema::hasColumn('expenses', 'recognition_method');
    }

    public static function overdue(?array $branchIds = null, ?string $through = null): int
    {
        if (! self::installed()) {
            return 0;
        }

        return ExpenseAllocation::whereNull('posted_at')->where('due_date', '<=', min($through ?? OperationContext::today(), OperationContext::today()))
            ->whereHas('expense', fn ($query) => $query->where('recognition_method', 'prepaid')->where('status', 'approved')
                ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds)))->count();
    }

    public function execute(Expense $expense, User $actor): int
    {
        abort_unless(self::installed(), 503, 'تحديث توزيع المصروفات لم يطبق على هذه النسخة.');

        return OperationContext::run(function () use ($expense, $actor): int {
            $current = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            FinanceContext::authorize($actor, $current->branch_id, 'finance.expenses.approve');
            abort_unless($current->recognition_method === 'prepaid' && $current->status === 'approved', 409, 'يلزم مصروف متعدد الأشهر معتمد.');
            $rows = $current->allocations()->orderBy('month')->lockForUpdate()->get();
            $this->assertFinancialSupport($current, $rows);
            $count = 0;
            foreach ($rows as $row) {
                $date = $row->due_date->toDateString();
                if ($row->posted_at || $date > OperationContext::today()) {
                    continue;
                }
                FinanceContext::authorize($actor, $current->branch_id, 'finance.expenses.approve', $date, false);
                $dimension = ['expense_id' => $current->id];
                app(PostJournalAction::class)->execute($row, 'expense_recognition', $current->branch_id, $actor, $date, [
                    ['account' => 'operating_expenses', 'amount' => $row->amount_minor] + $dimension,
                    ['account' => 'prepaid_expenses', 'amount' => -$row->amount_minor] + $dimension,
                ], 'حصة استفادة '.$current->number.' / '.$row->month->format('Y-m'));
                $row->update(['posted_at' => now()]);
                OperationContext::audit($current, $actor, 'expense.recognized', null, ['allocation_id' => $row->id, 'amount_minor' => $row->amount_minor, 'date' => $date]);
                $count++;
            }

            return $count;
        });
    }

    /** Verify evidence only, under the expense lock; never backfill or repair historical journals. */
    public function assertFinancialSupport(Expense $expense, Collection $rows): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Expense evidence locks require a transaction.');
        }
        app(ExpenseSchedule::class)->assertMatches($expense, $rows);
        $entries = JournalEntry::where('source_type', $expense->getMorphClass())->where('source_id', $expense->id)
            ->where('event', 'expense')->with(['lines.account', 'reversals'])->lockForUpdate()->get();
        if ($entries->count() !== 1) {
            $this->invalidSupport();
        }
        $this->assertJournal($entries->first(), $expense, $expense->transaction_date->toDateString(), [
            'prepaid_expenses' => [$expense->amount_minor, 0], 'expense_payables' => [0, $expense->amount_minor],
        ]);
        $recognitions = JournalEntry::where('source_type', (new ExpenseAllocation)->getMorphClass())->whereIn('source_id', $rows->modelKeys())
            ->with(['lines.account', 'reversals'])->lockForUpdate()->get()->groupBy('source_id');
        foreach ($rows as $row) {
            $matches = $recognitions->get($row->id, collect());
            if ($row->posted_at === null) {
                if ($matches->isNotEmpty()) {
                    $this->invalidSupport();
                }

                continue;
            }
            if ($matches->count() !== 1 || $matches->first()->event !== 'expense_recognition') {
                $this->invalidSupport();
            }
            $expected = $row->amount_minor === 0 ? [] : [
                'operating_expenses' => [$row->amount_minor, 0], 'prepaid_expenses' => [0, $row->amount_minor],
            ];
            $this->assertJournal($matches->first(), $expense, $row->due_date->toDateString(), $expected);
        }
    }

    /** @param array<string, array{int, int}> $expected */
    private function assertJournal(JournalEntry $entry, Expense $expense, string $date, array $expected): void
    {
        if ($entry->reversal_of_id !== null || $entry->reversals->isNotEmpty() || (int) $entry->branch_id !== (int) $expense->branch_id
            || $entry->transaction_date->toDateString() !== $date || $entry->lines->count() !== count($expected)) {
            $this->invalidSupport();
        }
        foreach ($entry->lines as $line) {
            $code = $line->account?->code;
            if (! isset($expected[$code ?? '']) || (int) $line->expense_id !== (int) $expense->id
                || [(int) $line->debit_minor, (int) $line->credit_minor] !== $expected[$code]) {
                $this->invalidSupport();
            }
            unset($expected[$code]);
        }
    }

    private function invalidSupport(): never
    {
        FinanceContext::fail('قيد أصل الاستفادة أو حصة التوزيع مفقود أو غير مطابق أو معكوس؛ أوقف العملية وراجع المصدر دون إعادة ترحيل تلقائية.');
    }
}
