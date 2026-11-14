<?php

namespace Tests\Unit;

use App\Actions\Finance\Expenses\ExpenseSchedule;
use PHPUnit\Framework\TestCase;

class ExpenseScheduleTest extends TestCase
{
    public function test_full_calendar_year_allocates_twelve_equal_months(): void
    {
        $rows = (new ExpenseSchedule)->build('2026-01-01', '2026-12-31', 120000);
        $this->assertCount(12, $rows);
        $this->assertSame(array_fill(0, 12, 10000), array_column($rows, 'amount_minor'));
    }

    public function test_partial_months_use_day_fractions_and_stable_cent_remainders(): void
    {
        $service = new ExpenseSchedule;
        $rows = $service->build('2026-01-16', '2026-03-15', 62000);
        $this->assertSame([16000, 31000, 15000], array_column($rows, 'amount_minor'));
        $this->assertSame($rows, $service->build('2026-01-16', '2026-03-15', 62000));
    }

    public function test_leap_day_one_day_and_large_amount_remain_exact(): void
    {
        $service = new ExpenseSchedule;
        $rows = $service->build('2028-02-29', '2028-02-29', 1);
        $this->assertSame(1, $rows[0]['amount_minor']);
        $rows = $service->build('2027-12-03', '2029-01-11', 99999999999999);
        $this->assertSame(99999999999999, array_sum(array_column($rows, 'amount_minor')));
    }
}
