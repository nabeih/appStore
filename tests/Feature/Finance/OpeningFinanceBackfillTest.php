<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\BackfillOpeningFinance;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Inventory\OpeningStock\OpeningFinancialSupport;
use App\Actions\Inventory\ProgressiveStockProof;
use App\Models\JournalEntry;
use App\Models\OpeningStockEntry;
use App\Models\User;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Sales\SalesTestCase;

class OpeningFinanceBackfillTest extends SalesTestCase
{
    public function test_corrected_and_inconsistent_legacy_documents_require_manual_review(): void
    {
        $source = $this->legacy();
        OperationContext::audit($source, $this->owner, 'inventory.document_corrected', 'تصحيح تاريخي مصطنع');
        $plan = app(BackfillOpeningFinance::class)->preview([$source->id], $this->branchId, 'دليل اختبار مصطنع', $this->owner);
        $this->assertSame('rejected', $plan['plans'][0]['status']);
        $this->assertStringContainsString('تصحيحات', implode(' ', $plan['plans'][0]['issues']));
        $source->items()->firstOrFail()->update(['unit_cost' => '19.00']);
        $plan = app(BackfillOpeningFinance::class)->preview([$source->id], $this->branchId, 'دليل اختبار مصطنع', $this->owner);
        $this->assertSame('rejected', $plan['plans'][0]['status']);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_reversed_opening_is_not_reposted_and_explicit_execute_still_requires_enabled_feature(): void
    {
        $this->stock('35', '20', 10);
        $source = OpeningStockEntry::sole();
        $entry = JournalEntry::sole();
        OperationContext::run(fn () => app(PostJournalAction::class)->reverse($entry, $this->owner, 'عكس مصطنع للاختبار', now()->toDateString()));
        $plan = app(BackfillOpeningFinance::class)->preview([$source->id], $this->branchId, 'دليل اختبار مصطنع', $this->owner);
        $this->assertSame('rejected', $plan['plans'][0]['status']);
        config(['finance.legacy_transition_enabled' => false]);
        $this->artisan('app:opening-finance-backfill', ['--actor' => $this->owner->id, '--branch' => $this->branchId,
            '--document' => [$source->id], '--evidence' => 'دليل اختبار مصطنع', '--reason' => 'قرار اختبار', '--execute' => true,
            '--confirmed' => true, '--revision' => $plan['revision']])->assertFailed();
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    protected bool $salesFixtureStarted = false;

    private function legacy(): OpeningStockEntry
    {
        $this->stock('35', '20', 10);
        $source = OpeningStockEntry::sole();
        $entry = JournalEntry::where('source_type', $source->getMorphClass())->where('source_id', $source->id)->sole();
        $entry->lines()->delete();
        $entry->delete();

        return $source;
    }

    public function test_default_cli_is_read_only_and_explicit_backfill_replays_without_changing_stock_or_cash(): void
    {
        $source = $this->legacy();
        $snapshot = [DB::table('inventories')->get()->toJson(), DB::table('stock_movements')->get()->toJson(), DB::table('audit_events')->count()];
        $this->artisan('app:opening-finance-backfill', ['--actor' => $this->owner->id, '--branch' => $this->branchId,
            '--document' => [$source->id], '--evidence' => 'دليل اختبار مصطنع'])->assertSuccessful();
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertSame($snapshot, [DB::table('inventories')->get()->toJson(), DB::table('stock_movements')->get()->toJson(), DB::table('audit_events')->count()]);
        $action = app(BackfillOpeningFinance::class);
        $plan = $action->preview([$source->id], $this->branchId, 'دليل اختبار مصطنع', $this->owner);
        $this->assertSame('eligible', $plan['plans'][0]['status']);
        config(['finance.legacy_transition_enabled' => true]);
        $first = $action->execute([$source->id], $this->branchId, 'دليل اختبار مصطنع', 'اعتماد اختبار موثق', $plan['revision'], $this->owner);
        $this->assertSame($first, $action->execute([$source->id], $this->branchId, 'دليل اختبار مصطنع', 'اعتماد اختبار موثق', $plan['revision'], $this->owner));
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertSame(20000, $this->balance('inventory'));
        $this->assertSame(-20000, $this->balance('opening_equity'));
        $this->assertSame(0, $this->balance('supplier_payables'));
        $this->assertSame(0, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame(array_slice($snapshot, 0, 2), [DB::table('inventories')->get()->toJson(), DB::table('stock_movements')->get()->toJson()]);
        app(OpeningFinancialSupport::class)->assertValid($source);
        $this->assertTrue(collect(app(ProgressiveStockProof::class)->build($this->branchId)['rows'])->every(fn ($row) => $row['ready']));
    }

    public function test_closed_period_and_conflicting_orphan_journal_block_backfill(): void
    {
        $source = $this->legacy();
        $action = app(BackfillOpeningFinance::class);
        DB::table('branches')->where('id', $this->branchId)->update(['financial_closed_through' => now()->toDateString()]);
        $this->assertSame('rejected', $action->preview([$source->id], $this->branchId, 'دليل اختبار مصطنع', $this->owner)['plans'][0]['status']);
        DB::table('branches')->where('id', $this->branchId)->update(['financial_closed_through' => null]);
        OperationContext::run(fn () => app(PostJournalAction::class)->execute($this->cash, 'orphan-test', $this->branchId, $this->owner, now()->toDateString(),
            [['account' => 'inventory', 'amount' => 20000], ['account' => 'opening_equity', 'amount' => -20000]], 'قيد مطابق مجهول المصدر'));
        $plan = $action->preview([$source->id], $this->branchId, 'دليل اختبار مصطنع', $this->owner);
        $this->assertSame('rejected', $plan['plans'][0]['status']);
        $this->assertStringContainsString('غير مرتبط', implode(' ', $plan['plans'][0]['issues']));
    }

    public function test_stale_preview_and_posting_failure_leave_no_partial_financial_repair(): void
    {
        $source = $this->legacy();
        config(['finance.legacy_transition_enabled' => true]);
        $action = app(BackfillOpeningFinance::class);
        $plan = $action->preview([$source->id], $this->branchId, 'دليل اختبار مصطنع', $this->owner);
        $source->update(['notes' => 'بيانات تغيرت بعد المعاينة']);
        try {
            $action->execute([$source->id], $this->branchId, 'دليل اختبار مصطنع', 'اعتماد اختبار موثق', $plan['revision'], $this->owner);
            $this->fail('Stale evidence must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->mock(PostJournalAction::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andThrow(ValidationException::withMessages(['account' => 'فشل ترحيل مصطنع']));
        });
        $action = app(BackfillOpeningFinance::class);
        $plan = $action->preview([$source->id], $this->branchId, 'دليل اختبار مصطنع', $this->owner);
        try {
            $action->execute([$source->id], $this->branchId, 'دليل اختبار مصطنع', 'اعتماد اختبار موثق', $plan['revision'], $this->owner);
            $this->fail('Posting failure must roll back.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('journal_entries', 0);
            $this->assertDatabaseCount('stock_movements', 1);
            $this->assertDatabaseMissing('audit_events', ['action' => 'finance.legacy_posted']);
        }
    }

    public function test_cli_requires_execution_guards_and_cashier_is_not_authorized(): void
    {
        $source = $this->legacy();
        $options = ['--actor' => $this->owner->id, '--branch' => $this->branchId, '--document' => [$source->id], '--evidence' => 'دليل اختبار مصطنع'];
        $this->artisan('app:opening-finance-backfill', $options + ['--execute' => true])->assertExitCode(2);
        $cashier = User::factory()->create(['role_id' => SalesAccess::provisionRole('sales_cashier')->id, 'branch_id' => $this->branchId]);
        $this->artisan('app:opening-finance-backfill', array_replace($options, ['--actor' => $cashier->id]))->assertFailed();
        $this->assertDatabaseCount('journal_entries', 0);
    }
}
