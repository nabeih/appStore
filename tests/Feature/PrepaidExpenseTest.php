<?php

namespace Tests\Feature;

use App\Actions\Finance\Expenses\ExpensePeriodReport;
use App\Actions\Finance\Expenses\ExpenseSchedule;
use App\Actions\Finance\Expenses\ManageExpenseAction;
use App\Actions\Finance\Expenses\RecognizePrepaidExpense;
use App\Actions\Finance\FinanceReports;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Inventory\SetupReadinessReport;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseAllocation;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Permission;
use App\Models\User;
use App\Support\Sales\SalesAccess;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Sales\SalesTestCase;

class PrepaidExpenseTest extends SalesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'appstore_golive_20261031_tests') {
            throw new RuntimeException('Only the isolated MySQL sandbox is permitted.');
        }
        $this->travelTo(Carbon::parse('2026-01-01 12:00:00'));
        app(PostJournalAction::class)->execute($this->cash, 'opening', $this->branchId, $this->owner, '2026-01-01', [
            ['account' => $this->cash->id, 'amount' => 200000], ['account' => 'opening_equity', 'amount' => -200000]], 'رصيد اختبار مستقل');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'title' => 'إيجار عرض مستقل',
            'category' => 'rent', 'recognition_method' => 'prepaid', 'transaction_date' => '2026-01-01',
            'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'amount' => '1200.00'], $changes);
    }

    private function approved(array $changes = []): Expense
    {
        $expense = app(ManageExpenseAction::class)->save($this->payload($changes), $this->owner);

        return app(ManageExpenseAction::class)->approve($expense, $this->owner);
    }

    public function test_expense_form_explains_unavailable_schedule_instead_of_hiding_the_option(): void
    {
        $actual = Schema::getFacadeRoot();
        $schema = \Mockery::mock($actual);
        $schema->shouldReceive('hasTable')->andReturnUsing(fn (string $table): bool => $table !== 'expense_allocations' && $actual->hasTable($table));
        Schema::swap($schema);
        $this->get(route('finance.expenses.index'))->assertOk()
            ->assertSee('مصروف مباشر')->assertSee('مصروف متعدد الأشهر — توزيع الاستفادة')
            ->assertSee('value="prepaid" disabled', false)
            ->assertSee('تحديث توزيع المصروفات لم يُطبّق على قاعدة هذه النسخة');
    }

    public function test_annual_rent_draft_previews_twelve_monthly_shares_of_one_thousand_without_financial_effects(): void
    {
        $this->get(route('finance.expenses.index'))->assertOk()
            ->assertSee('value="prepaid"', false)->assertDontSee('value="prepaid" disabled', false)
            ->assertSee('يظهر جدول التوزيع للمراجعة قبل الاعتماد');
        $before = JournalEntry::count();
        $this->postJson(route('finance.expenses.store'), $this->payload(['amount' => '12000.00']))->assertOk();
        $expense = Expense::firstOrFail();
        $this->assertSame('draft', $expense->status);
        $this->assertSame(12, $expense->allocations()->count());
        $this->assertSame(1200000, (int) $expense->allocations()->sum('amount_minor'));
        $this->assertSame([100000], $expense->allocations()->pluck('amount_minor')->unique()->values()->all());
        $this->assertSame($before, JournalEntry::count());
        $this->assertDatabaseCount('expense_payments', 0);
        $this->get(route('finance.expenses.show', $expense))->assertOk()
            ->assertSee('جدول توزيع الاستفادة')->assertSee('1000.00');
    }

    public function test_draft_previews_twelve_shares_without_ledger_and_approval_establishes_only_asset_and_liability(): void
    {
        $data = $this->payload();
        $this->postJson(route('finance.expenses.store'), $data)->assertOk();
        $expense = Expense::firstOrFail();
        $this->postJson(route('finance.expenses.store'), $data)->assertOk();
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('expense_allocations', 12);
        $this->assertSame(120000, (int) $expense->allocations()->sum('amount_minor'));
        $this->assertSame(0, $this->balance('operating_expenses'));
        $this->assertSame(0, $this->balance('prepaid_expenses'));
        $this->get(route('finance.expenses.show', $expense))->assertOk()->assertSee('جدول توزيع الاستفادة');
        $this->postJson(route('finance.expenses.approve', $expense))->assertOk();
        $this->postJson(route('finance.expenses.approve', $expense))->assertOk();
        $this->assertSame(120000, $this->balance('prepaid_expenses'));
        $this->assertSame(-120000, $this->balance('expense_payables'));
        $this->assertSame(0, $this->balance('operating_expenses'));
        $this->assertDatabaseCount('expense_payments', 0);
    }

    public function test_payment_is_once_and_monthly_catch_up_does_not_create_cash_payments(): void
    {
        $expense = $this->approved();
        $payment = ['client_uuid' => (string) Str::uuid(), 'account_id' => $this->cash->id, 'amount' => '1200', 'reason' => 'دفع سنة كاملة'];
        $this->postJson(route('finance.expenses.pay', $expense), $payment)->assertOk();
        $this->postJson(route('finance.expenses.pay', $expense), $payment)->assertOk();
        $this->assertDatabaseCount('expense_payments', 1);
        $this->travelTo(Carbon::parse('2026-03-15 12:00:00'));
        $this->postJson(route('finance.expenses.recognize', $expense))->assertOk();
        $this->postJson(route('finance.expenses.recognize', $expense))->assertOk();
        $this->assertSame(2, $expense->allocations()->whereNotNull('posted_at')->count());
        $this->assertSame(20000, $this->balance('operating_expenses'));
        $this->assertSame(100000, $this->balance('prepaid_expenses'));
        $this->assertSame(0, $this->balance('expense_payables'));
        $this->assertDatabaseCount('expense_payments', 1);
        $report = app(ExpensePeriodReport::class)->build($this->owner, $this->branchId, '2026-02-01', '2026-02-28');
        $this->assertSame(10000, $report['expenses_minor']);
        $this->assertSame(0, $report['cash_paid_minor']);
        $this->assertSame(100000, $report['prepaid_remaining_minor']);
    }

    public function test_partial_months_cross_year_and_rounding_preserve_exact_total(): void
    {
        $schedule = app(ExpenseSchedule::class);
        $rows = $schedule->build('2026-11-16', '2027-02-15', 10001);
        $this->assertCount(4, $rows);
        $this->assertSame(10001, array_sum(array_column($rows, 'amount_minor')));
        $this->assertSame('2027-02-15', $rows[3]['due_date']);
        $this->assertLessThanOrEqual(1, abs($rows[1]['amount_minor'] - $rows[2]['amount_minor']));
        $this->assertLessThan($rows[1]['amount_minor'], $rows[0]['amount_minor']);
        $tiny = $schedule->build('2026-01-01', '2026-12-31', 1);
        $this->assertSame(1, array_sum(array_column($tiny, 'amount_minor')));
        $this->assertSame(1, $tiny[0]['amount_minor']);
    }

    public function test_closed_due_period_rolls_back_entire_catch_up_and_stays_incomplete(): void
    {
        $expense = $this->approved();
        $this->travelTo(Carbon::parse('2026-03-15 12:00:00'));
        Branch::whereKey($this->branchId)->update(['financial_closed_through' => '2026-01-31']);
        $this->postJson(route('finance.expenses.recognize', $expense))->assertUnprocessable();
        $this->assertSame(0, $expense->allocations()->whereNotNull('posted_at')->count());
        $this->assertSame(0, $this->balance('operating_expenses'));
        $this->assertSame(2, RecognizePrepaidExpense::overdue([$this->branchId]));
        $this->assertFalse(app(FinanceReports::class)->completeness([$this->branchId])['complete']);
        $this->artisan('app:expenses-recognize')->assertFailed();
    }

    public function test_journal_failure_rolls_back_allocations_and_lines_then_retry_is_safe(): void
    {
        $expense = $this->approved();
        $this->travelTo(Carbon::parse('2026-02-01 12:00:00'));
        $before = JournalEntry::count();
        $beforeLines = JournalLine::count();
        JournalLine::created(function (JournalLine $line): void {
            if ($line->expense_id !== null) {
                throw new RuntimeException('Injected posting failure');
            }
        });
        try {
            app(RecognizePrepaidExpense::class)->execute($expense, $this->owner);
            $this->fail('Expected injected failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected posting failure', $error->getMessage());
        } finally {
            JournalLine::flushEventListeners();
        }
        $this->assertSame($before, JournalEntry::count());
        $this->assertSame($beforeLines, JournalLine::count());
        $this->assertSame(0, $expense->allocations()->whereNotNull('posted_at')->count());
        $this->assertSame(1, app(RecognizePrepaidExpense::class)->execute($expense, $this->owner));
    }

    public function test_reversal_returns_asset_expense_payable_and_cash_without_deleting_history(): void
    {
        $expense = $this->approved();
        $action = app(ManageExpenseAction::class);
        $payment = $action->pay($expense, ['client_uuid' => (string) Str::uuid(), 'account_id' => $this->cash->id, 'amount' => '1200', 'reason' => 'دفع تجريبي'], $this->owner);
        $this->travelTo(Carbon::parse('2026-02-01 12:00:00'));
        app(RecognizePrepaidExpense::class)->execute($expense, $this->owner);
        $this->postJson(route('finance.expenses.reverse', $expense), ['reason' => 'إلغاء عقد موثق'])->assertUnprocessable();
        $action->reversePayment($payment, $this->owner, 'استرداد فعلي كامل');
        $action->reverse($expense, $this->owner, 'إلغاء عقد موثق');
        $action->reverse($expense, $this->owner, 'إلغاء عقد موثق');
        $this->assertSame(0, $this->balance('operating_expenses'));
        $this->assertSame(0, $this->balance('prepaid_expenses'));
        $this->assertSame(0, $this->balance('expense_payables'));
        $this->assertSame(200000, $this->balance('CASH-TEST'));
        $this->assertSame('reversed', $expense->fresh()->status);
        $this->assertDatabaseCount('expense_allocations', 12);
        $reports = app(ExpensePeriodReport::class);
        $january = $reports->build($this->owner, $this->branchId, '2026-01-01', '2026-01-31');
        $february = $reports->build($this->owner, $this->branchId, '2026-02-01', '2026-02-28');
        $annual = $reports->build($this->owner, $this->branchId, '2026-01-01', '2026-12-31');
        $this->assertSame(10000, $january['expenses_minor']);
        $this->assertSame(120000, $january['cash_paid_minor']);
        $this->assertSame(-10000, $february['expenses_minor']);
        $this->assertSame(-120000, $february['cash_paid_minor']);
        $this->assertSame(0, $annual['expenses_minor']);
        $this->assertSame(0, $annual['cash_paid_minor']);
    }

    public function test_cashier_and_other_branch_cannot_read_or_post_and_exports_are_scoped(): void
    {
        $expense = $this->approved();
        $params = ['branch_id' => $this->branchId, 'from' => '2026-01-01', 'to' => '2026-12-31'];
        $this->get(route('finance.expenses.report', $params))->assertOk();
        $this->getJson(route('finance.expenses.report', $params))->assertOk()->assertJsonPath('data.prepaid_remaining_minor', 120000);
        $this->get(route('finance.expenses.report', $params + ['export' => 'xlsx']))->assertOk()->assertDownload('expense-period.xlsx');
        $cashier = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $this->actingAs($cashier)->postJson(route('finance.expenses.recognize', $expense))->assertForbidden();
        $this->getJson(route('finance.expenses.report', $params))->assertForbidden();
        $otherBranch = Branch::factory()->create();
        $this->owner->update(['branch_id' => $otherBranch->id]);
        $this->owner->role->permissions()->detach(Permission::where('name', 'sales.all_branches')->value('id'));
        $this->actingAs($this->owner->fresh())->getJson(route('finance.expenses.report', $params))->assertForbidden();
    }

    public function test_immediate_expense_has_no_schedule_and_is_not_processed_by_scheduler(): void
    {
        $expense = $this->approved(['recognition_method' => 'immediate', 'period_end' => '2026-01-31']);
        $this->assertSame(120000, $this->balance('operating_expenses'));
        $this->assertSame(0, ExpenseAllocation::count());
        $this->artisan('app:expenses-recognize')->assertSuccessful();
        $this->assertSame(120000, $this->balance('operating_expenses'));
        $this->assertSame('approved', $expense->fresh()->status);
    }

    public function test_scheduler_catches_up_and_rerun_has_no_duplicate_effect(): void
    {
        $expense = $this->approved();
        $this->travelTo(Carbon::parse('2027-01-01 12:00:00'));
        $this->artisan('app:expenses-recognize')->assertSuccessful();
        $this->artisan('app:expenses-recognize')->assertSuccessful();
        $this->assertSame(12, $expense->allocations()->whereNotNull('posted_at')->count());
        $this->assertSame(120000, $this->balance('operating_expenses'));
        $this->assertSame(0, $this->balance('prepaid_expenses'));
        $this->assertDatabaseCount('expense_payments', 0);
    }

    public function test_schedule_tampering_is_rejected_without_original_posting(): void
    {
        $expense = app(ManageExpenseAction::class)->save($this->payload(), $this->owner);
        $expense->allocations()->firstOrFail()->update(['amount_minor' => 1]);
        $this->postJson(route('finance.expenses.approve', $expense))->assertUnprocessable();
        $this->assertSame(0, $this->balance('prepaid_expenses'));
        $this->assertSame('draft', $expense->fresh()->status);
    }

    public function test_tampering_after_approval_is_rejected_even_when_total_is_unchanged(): void
    {
        $expense = $this->approved();
        $rows = $expense->allocations()->orderBy('month')->get();
        $rows[0]->update(['amount_minor' => 9999]);
        $rows[1]->update(['amount_minor' => 10001]);
        $this->travelTo(Carbon::parse('2026-02-01 12:00:00'));
        $this->postJson(route('finance.expenses.recognize', $expense))->assertUnprocessable();
        $this->assertSame(0, $expense->allocations()->whereNotNull('posted_at')->count());
        $this->assertSame(0, $this->balance('operating_expenses'));
    }

    public function test_failure_of_initial_posting_does_not_leave_an_asset_account_or_approval(): void
    {
        $expense = app(ManageExpenseAction::class)->save($this->payload(), $this->owner);
        JournalLine::created(function (JournalLine $line): void {
            if ($line->expense_id !== null) {
                throw new RuntimeException('Initial posting failed');
            }
        });
        try {
            app(ManageExpenseAction::class)->approve($expense, $this->owner);
            $this->fail('Expected initial posting failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Initial posting failed', $error->getMessage());
        } finally {
            JournalLine::flushEventListeners();
        }
        $this->assertSame('draft', $expense->fresh()->status);
        $this->assertDatabaseMissing('financial_accounts', ['code' => 'prepaid_expenses']);
        $this->assertSame(0, $this->balance('expense_payables'));
    }

    public function test_reversed_source_is_not_silently_recreated_and_due_shares_block_setup_completion(): void
    {
        $expense = $this->approved();
        $entry = JournalEntry::where('source_type', $expense->getMorphClass())->where('source_id', $expense->id)->firstOrFail();
        app(PostJournalAction::class)->reverse($entry, $this->owner, 'اختبار مصدر غير صالح', '2026-01-01');
        $this->travelTo(Carbon::parse('2026-02-01 12:00:00'));
        $this->postJson(route('finance.expenses.recognize', $expense))->assertUnprocessable();
        $this->assertSame(0, $expense->allocations()->whereNotNull('posted_at')->count());
        $report = app(SetupReadinessReport::class)->build(Branch::findOrFail($this->branchId));
        $this->assertContains('due_expense_allocations', array_column($report['blockers'], 'code'));
    }

    public function test_scheduler_does_not_use_revoked_approval_permission(): void
    {
        $expense = $this->approved();
        $this->travelTo(Carbon::parse('2026-02-01 12:00:00'));
        $this->owner->role->permissions()->detach(Permission::where('name', 'finance.expenses.approve')->value('id'));
        $this->artisan('app:expenses-recognize')->assertFailed();
        $this->assertSame(0, $expense->allocations()->whereNotNull('posted_at')->count());
    }

    public function test_report_rejects_aggregate_overflow_instead_of_silently_clamping_or_using_float(): void
    {
        $expense = $this->approved();
        foreach (['large_fixture_a', 'large_fixture_b'] as $event) {
            app(PostJournalAction::class)->execute($expense, $event, $this->branchId, $this->owner, '2026-01-01', [
                ['account' => 'operating_expenses', 'amount' => 4611686018427387904, 'expense_id' => $expense->id],
                ['account' => 'expense_payables', 'amount' => -4611686018427387904, 'expense_id' => $expense->id],
            ], 'حد رقمي في قاعدة اختبار مستقلة');
        }
        $this->getJson(route('finance.expenses.report', ['branch_id' => $this->branchId, 'from' => '2026-01-01', 'to' => '2026-01-31']))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_existing_wrong_recognition_cannot_be_accepted_as_a_successful_retry(): void
    {
        $expense = $this->approved();
        $row = $expense->allocations()->orderBy('month')->firstOrFail();
        app(PostJournalAction::class)->execute($row, 'expense_recognition', $this->branchId, $this->owner, '2026-01-31', [
            ['account' => 'operating_expenses', 'amount' => 1, 'expense_id' => $expense->id],
            ['account' => 'prepaid_expenses', 'amount' => -1, 'expense_id' => $expense->id],
        ], 'مصدر اختبار غير مطابق');
        $this->travelTo(Carbon::parse('2026-02-01 12:00:00'));
        $before = JournalEntry::count();
        $this->postJson(route('finance.expenses.recognize', $expense))->assertUnprocessable();
        $this->assertNull($row->fresh()->posted_at);
        $this->assertSame($before, JournalEntry::count());
    }

    public function test_annual_and_monthly_reports_separate_cash_from_recognition_and_all_journals_balance(): void
    {
        $expense = $this->approved();
        app(ManageExpenseAction::class)->pay($expense, ['client_uuid' => (string) Str::uuid(), 'account_id' => $this->cash->id,
            'amount' => '1200.00', 'reason' => 'دفع السنة مقدمًا'], $this->owner);
        $this->travelTo(Carbon::parse('2027-01-01 12:00:00'));
        $this->assertSame(12, app(RecognizePrepaidExpense::class)->execute($expense, $this->owner));
        $this->assertSame(0, app(RecognizePrepaidExpense::class)->execute($expense, $this->owner));
        $reports = app(ExpensePeriodReport::class);
        foreach (range(1, 12) as $month) {
            $date = Carbon::create(2026, $month, 1);
            $report = $reports->build($this->owner, $this->branchId, $date->toDateString(), $date->endOfMonth()->toDateString());
            $this->assertSame(10000, $report['expenses_minor']);
            $this->assertSame($month === 1 ? 120000 : 0, $report['cash_paid_minor']);
            $this->assertSame(120000 - 10000 * $month, $report['prepaid_remaining_minor']);
        }
        $report = $reports->build($this->owner, $this->branchId, '2026-01-01', '2026-12-31');
        $this->assertSame(120000, $report['expenses_minor']);
        $this->assertSame(120000, $report['cash_paid_minor']);
        $this->assertSame(0, $report['due_allocations']);
        foreach (JournalEntry::with('lines')->get() as $journal) {
            $this->assertSame((string) $journal->lines->sum('debit_minor'), (string) $journal->lines->sum('credit_minor'));
        }
        $this->assertDatabaseCount('expense_payments', 1);
    }

    public function test_posted_marker_without_a_journal_blocks_recognition_and_reversal(): void
    {
        $expense = $this->approved();
        $row = $expense->allocations()->orderBy('month')->firstOrFail();
        $row->update(['posted_at' => now()]);
        $this->travelTo(Carbon::parse('2026-03-01 12:00:00'));
        $before = JournalEntry::count();
        $this->postJson(route('finance.expenses.recognize', $expense))->assertUnprocessable();
        $this->postJson(route('finance.expenses.reverse', $expense), ['reason' => 'اختبار سلامة المصدر'])->assertUnprocessable();
        $this->assertSame($before, JournalEntry::count());
        $this->assertSame('approved', $expense->fresh()->status);
    }

    public static function invalidRecognitionCases(): array
    {
        return ['amount' => ['amount'], 'date' => ['date'], 'branch' => ['branch'], 'dimension' => ['dimension'], 'reversed' => ['reversed']];
    }

    #[DataProvider('invalidRecognitionCases')]
    public function test_posted_recognition_must_match_its_share_before_catch_up_or_reversal(string $kind): void
    {
        $expense = $this->approved();
        $row = $expense->allocations()->orderBy('month')->firstOrFail();
        $amount = $kind === 'amount' ? 1 : $row->amount_minor;
        $entry = app(PostJournalAction::class)->execute($row, 'expense_recognition',
            $kind === 'branch' ? Branch::factory()->create()->id : $this->branchId, $this->owner,
            $kind === 'date' ? '2026-01-30' : '2026-01-31', [
                ['account' => 'operating_expenses', 'amount' => $amount, 'expense_id' => $kind === 'dimension' ? null : $expense->id],
                ['account' => 'prepaid_expenses', 'amount' => -$amount, 'expense_id' => $expense->id],
            ], 'دليل اختبار غير متسق');
        $row->update(['posted_at' => now()]);
        if ($kind === 'reversed') {
            app(PostJournalAction::class)->reverse($entry, $this->owner, 'عكس اختبار مستقل', '2026-01-31');
        }
        $this->travelTo(Carbon::parse('2026-03-01 12:00:00'));
        $before = JournalEntry::count();
        $this->postJson(route('finance.expenses.recognize', $expense))->assertUnprocessable();
        $this->postJson(route('finance.expenses.reverse', $expense), ['reason' => 'اختبار مطابقة الحصص'])->assertUnprocessable();
        $this->assertSame($before, JournalEntry::count());
        $this->assertSame(1, $expense->allocations()->whereNotNull('posted_at')->count());
        $this->assertSame('approved', $expense->fresh()->status);
    }

    public function test_failure_during_recognition_reversal_rolls_back_the_entire_reversal(): void
    {
        $expense = $this->approved();
        $this->travelTo(Carbon::parse('2026-03-01 12:00:00'));
        app(RecognizePrepaidExpense::class)->execute($expense, $this->owner);
        $before = [JournalEntry::count(), JournalLine::count(), DB::table('audit_events')->count()];
        JournalLine::created(function (): void {
            throw new RuntimeException('Injected reversal failure');
        });
        try {
            app(ManageExpenseAction::class)->reverse($expense, $this->owner, 'اختبار تراجع العكس');
            $this->fail('Expected injected failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected reversal failure', $error->getMessage());
        } finally {
            JournalLine::flushEventListeners();
        }
        $this->assertSame($before, [JournalEntry::count(), JournalLine::count(), DB::table('audit_events')->count()]);
        $this->assertSame('approved', $expense->fresh()->status);
        $this->assertSame(100000, $this->balance('prepaid_expenses'));
        $this->assertSame(20000, $this->balance('operating_expenses'));
    }

    public function test_database_rejects_a_duplicate_month_without_changing_the_schedule(): void
    {
        $expense = app(ManageExpenseAction::class)->save($this->payload(), $this->owner);
        try {
            $expense->allocations()->firstOrFail()->replicate()->save();
            $this->fail('A second allocation for the same expense and month must fail.');
        } catch (QueryException $error) {
            $this->assertSame('23000', (string) $error->getCode());
        }
        $this->assertSame(12, $expense->allocations()->count());
        $this->assertSame(120000, (int) $expense->allocations()->sum('amount_minor'));
        $this->assertSame('draft', $expense->fresh()->status);
        $this->assertSame(0, $expense->allocations()->whereNotNull('posted_at')->count());
    }

    public function test_json_float_amount_cannot_silently_drop_cents_when_saving_an_expense(): void
    {
        $response = $this->postJson(route('finance.expenses.store'), $this->payload(['amount' => 1200.99]));
        $this->assertSame(422, $response->status(), 'Persisted expense amount_minor: '.var_export(Expense::value('amount_minor'), true));
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('expense_allocations', 0);
    }

    public function test_json_float_payment_cannot_silently_drop_cents_or_create_a_payment(): void
    {
        $expense = $this->approved();
        $before = JournalEntry::count();
        $response = $this->postJson(route('finance.expenses.pay', $expense), ['client_uuid' => (string) Str::uuid(), 'account_id' => $this->cash->id,
            'amount' => 100.99, 'reason' => 'اختبار مدخل غير دقيق']);
        $this->assertSame(422, $response->status(), 'Persisted payment amount_minor: '.var_export(DB::table('expense_payments')->value('amount_minor'), true));
        $this->assertDatabaseCount('expense_payments', 0);
        $this->assertSame($before, JournalEntry::count());
    }

    public function test_decimal_string_expense_and_payment_preserve_every_cent(): void
    {
        $expense = $this->approved(['amount' => '1200.99']);
        $this->assertSame(120099, $expense->amount_minor);
        $this->assertSame(120099, (int) $expense->allocations()->sum('amount_minor'));
        $this->postJson(route('finance.expenses.pay', $expense), ['client_uuid' => (string) Str::uuid(), 'account_id' => $this->cash->id,
            'amount' => '100.99', 'reason' => 'حفظ الكسور المالية'])->assertOk();
        $this->assertSame(10099, (int) DB::table('expense_payments')->value('amount_minor'));
        $this->assertSame(-110000, $this->balance('expense_payables'));
        $this->assertSame(189901, $this->balance('CASH-TEST'));
    }
}
