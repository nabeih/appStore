<?php

namespace App\Actions\Finance\Expenses;

use App\Actions\Finance\FinanceContext;
use App\Models\Expense;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ExpenseSchedule
{
    public function assertMatches(Expense $expense, Collection $rows): void
    {
        $expected = $this->build($expense->period_start->toDateString(), $expense->period_end->toDateString(), $expense->amount_minor);
        $actual = $rows->map(fn ($row): array => ['month' => $row->month->toDateString(), 'period_start' => $row->period_start->toDateString(),
            'period_end' => $row->period_end->toDateString(), 'due_date' => $row->due_date->toDateString(), 'amount_minor' => $row->amount_minor])->all();
        if ($actual !== $expected) {
            FinanceContext::fail('جدول التوزيع تغير أو لا يطابق المستند؛ أوقف الترحيل وراجع المسؤول.');
        }
    }

    /** @return list<array{month:string,period_start:string,period_end:string,due_date:string,amount_minor:int}> */
    public function build(string $start, string $end, int $amount): array
    {
        $first = CarbonImmutable::parse($start);
        $last = CarbonImmutable::parse($end);
        if ($amount <= 0 || $first > $last || $last > $first->addYears(10)) {
            FinanceContext::fail('حدد مبلغًا موجبًا وفترة استفادة لا تتجاوز عشر سنوات.');
        }
        $rows = $weights = [];
        for ($month = $first->startOfMonth(); $month <= $last; $month = $month->addMonth()) {
            $from = $month->max($first);
            $to = $month->endOfMonth()->min($last)->startOfDay();
            $weights[] = ($to->day - $from->day + 1) * intdiv(377580, $month->daysInMonth);
            $rows[] = ['month' => $month->toDateString(), 'period_start' => $from->toDateString(), 'period_end' => $to->toDateString(),
                'due_date' => $to->toDateString(), 'amount_minor' => 0];
        }
        $total = array_sum($weights);
        $remainders = [];
        foreach ($weights as $index => $weight) {
            [$share, $remainder] = BigInteger::of($amount)->multipliedBy($weight)->quotientAndRemainder($total);
            $rows[$index]['amount_minor'] = $share->toInt();
            $remainders[$index] = $remainder->toInt();
        }
        arsort($remainders, SORT_NUMERIC);
        $left = $amount - array_sum(array_column($rows, 'amount_minor'));
        foreach (array_keys($remainders) as $index) {
            if ($left-- <= 0) {
                break;
            }
            $rows[$index]['amount_minor']++;
        }

        return $rows;
    }
}
