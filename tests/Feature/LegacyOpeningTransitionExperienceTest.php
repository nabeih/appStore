<?php

namespace Tests\Feature;

use App\Actions\Finance\PostJournalAction;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\OpeningStockEntry;
use App\Models\OpeningStockItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sales\SalesTestCase;

class LegacyOpeningTransitionExperienceTest extends SalesTestCase
{
    protected bool $salesFixtureStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'appstore_golive_20261031_tests') {
            throw new \LogicException('Only the isolated MySQL sandbox is permitted.');
        }
        config(['finance.legacy_transition_enabled' => true]);
    }

    private function legacy(string $code = 'LEGACY-A', int $quantity = 28): OpeningStockEntry
    {
        $variant = $this->stock('80', '65', $quantity, $code);
        $entry = OpeningStockEntry::whereHas('items', fn ($query) => $query->where('product_variant_id', $variant->id))->firstOrFail();
        $journal = JournalEntry::where('source_type', OpeningStockEntry::class)->where('source_id', $entry->id)->firstOrFail();
        $journal->lines()->delete();
        $journal->delete();

        return $entry;
    }

    private function input(OpeningStockEntry $entry): array
    {
        return ['source_kind' => 'opening', 'source_id' => $entry->id, 'branch_id' => $entry->branch_id,
            'posting_date' => $entry->opening_date->toDateString(), 'evidence' => 'محضر جرد بداية موثق للمجموعة', 'reason' => 'اعتماد الأثر المالي للافتتاح', 'confirmed' => false];
    }

    private function protectedState(): array
    {
        return [DB::table('inventories')->orderBy('id')->get()->toArray(), DB::table('stock_movements')->orderBy('id')->get()->toArray(),
            DB::table('supplier_payments')->orderBy('id')->get()->toArray(), DB::table('expense_payments')->orderBy('id')->get()->toArray(),
            $this->balance('CASH-TEST'), DB::table('financial_accounts')->orderBy('id')->get()->toArray(),
            DB::table('journal_entries')->where('source_type', '!=', OpeningStockEntry::class)->orderBy('id')->get()->toArray(),
            DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('e.source_type', '!=', OpeningStockEntry::class)->orderBy('l.id')->get(['l.*'])->toArray()];
    }

    public function test_single_opening_ignores_payment_fields_and_requires_consent_then_posts_once_without_stock_or_cash_changes(): void
    {
        $entry = $this->legacy();
        $bank = FinancialAccount::create(['code' => 'BANK-TRANSITION', 'name' => 'بنك الاختبار', 'type' => 'bank', 'branch_id' => $this->branchId]);
        foreach ([$this->cash, $bank] as $account) {
            OperationContext::run(fn () => app(PostJournalAction::class)->execute($account, 'opening', $this->branchId, $this->owner, OperationContext::today(),
                [['account' => $account->id, 'amount' => 10000], ['account' => 'opening_equity', 'amount' => -10000]], 'قيد معتمد سابق محفوظ'));
        }
        $input = $this->input($entry) + ['account_id' => 'not-an-account', 'funding_confirmed' => 'invalid', 'opening_inclusion' => 'invalid', 'funding_evidence' => []];
        $before = $this->protectedState();
        $plan = $this->postJson(route('finance.transition.preview'), $input)->assertOk()->assertJsonPath('status', 'eligible')->assertJsonPath('amount_text', '1820.00')->json();
        $input['revision'] = $plan['revision'];
        $this->postJson(route('finance.transition.post'), $input)->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $input['confirmed'] = true;
        $id = $this->postJson(route('finance.transition.post'), $input)->assertOk()->json('journal_id');
        $this->postJson(route('finance.transition.post'), $input)->assertOk()->assertJsonPath('journal_id', $id);
        $this->assertEquals($before, $this->protectedState());
        $this->assertSame(182000, $this->balance('inventory'));
        $this->assertSame(-202000, $this->balance('opening_equity'));
        $this->assertSame(1, AuditEvent::where('action', 'finance.legacy_posted')->where('subject_id', $entry->id)->count());
        $this->assertSame(['inventory', 'opening_equity'], JournalEntry::findOrFail($id)->lines()->with('account')->get()->pluck('account.code')->sort()->values()->all());
    }

    public function test_selected_openings_use_individual_endpoints_and_display_a_blocked_document_without_posting_it(): void
    {
        $entries = [$this->legacy(), $this->legacy('LEGACY-B', 2), $this->legacy('LEGACY-BLOCKED', 1)];
        $badItem = $entries[2]->items()->firstOrFail();
        DB::table('stock_movements')->where('source_type', OpeningStockItem::class)->where('source_id', $badItem->id)->update(['quantity_change' => 99]);
        $before = $this->protectedState();
        $view = $this->get(route('finance.transition.index'))->assertOk()->assertSee('data-opening-batch-form', false)->assertSee('سبب الاعتماد المشترك');
        $rows = collect($view->viewData('openingRows'))->keyBy('id');
        $this->assertSame('eligible', $rows[$entries[0]->id]['status']);
        $this->assertSame('eligible', $rows[$entries[1]->id]['status']);
        $this->assertSame('rejected', $rows[$entries[2]->id]['status']);
        $this->assertStringContainsString('مفقودة أو متعارضة', implode(' ', $rows[$entries[2]->id]['issues']));
        $plans = $responses = [];
        foreach ($entries as $entry) {
            $input = $this->input($entry);
            $plan = $this->postJson(route('finance.transition.preview'), $input)->assertOk()->json();
            $plans[$entry->id] = $plan;
            $input['revision'] = $plan['revision'];
            $input['confirmed'] = true;
            if ($plan['status'] === 'rejected') {
                $this->postJson(route('finance.transition.post'), $input)->assertUnprocessable();

                continue;
            }
            $responses[$entry->id] = $this->postJson(route('finance.transition.post'), $input)->assertOk()->json();
            $this->postJson(route('finance.transition.post'), $input)->assertOk()->assertJsonPath('journal_id', $responses[$entry->id]['journal_id']);
        }
        $this->assertSame(2, AuditEvent::where('action', 'finance.legacy_posted')->count());
        $this->assertSame(195000, $this->balance('inventory'));
        $this->assertSame(-195000, $this->balance('opening_equity'));
        $this->assertEquals($before, $this->protectedState());
        foreach (JournalEntry::with('lines')->get() as $journal) {
            $this->assertSame((int) $journal->lines->sum('debit_minor'), (int) $journal->lines->sum('credit_minor'));
        }
        if (getenv('LEGACY_TRANSITION_UI_FIXTURE') === '1') {
            file_put_contents(storage_path('app/reports/legacy-transition-ui-fixture.json'), json_encode(['html' => $view->getContent(), 'plans' => $plans, 'responses' => $responses], JSON_THROW_ON_ERROR));
        }
    }

    public function test_changed_preview_closed_feature_missing_revision_and_payment_validation_cannot_post(): void
    {
        $entry = $this->legacy();
        $input = $this->input($entry);
        $plan = $this->postJson(route('finance.transition.preview'), $input)->assertOk()->json();
        $input['confirmed'] = true;
        $this->postJson(route('finance.transition.post'), $input)->assertConflict();
        $input['revision'] = $plan['revision'];
        $entry->update(['notes' => 'تغير المستند بعد المعاينة']);
        $this->postJson(route('finance.transition.post'), $input)->assertConflict();
        config(['finance.legacy_transition_enabled' => false]);
        $this->postJson(route('finance.transition.post'), $input)->assertForbidden();
        config(['finance.legacy_transition_enabled' => true]);
        $payment = array_replace($input, ['source_kind' => 'payment']);
        $this->postJson(route('finance.transition.post'), $payment)->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id', 'funding_confirmed', 'funding_evidence', 'opening_inclusion']);
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_permission_and_branch_checks_apply_to_each_opening_and_readiness_table(): void
    {
        $entry = $this->legacy();
        $input = $this->input($entry);
        $input['branch_id'] = Branch::factory()->create()->id;
        $this->postJson(route('finance.transition.preview'), $input)->assertForbidden();
        $role = Role::create(['name' => 'transition_reader', 'display_name' => 'قارئ الانتقال', 'is_active' => true]);
        $role->permissions()->sync(Permission::whereIn('name', ['finance.transition_review', 'sales.view_cost'])->pluck('id'));
        $this->actingAs(User::factory()->create(['branch_id' => $this->branchId, 'role_id' => $role->id]));
        $view = $this->get(route('finance.transition.index'))->assertOk();
        $this->assertSame('rejected', $view->viewData('openingRows')[0]['status']);
        $this->postJson(route('finance.transition.preview'), $this->input($entry))->assertForbidden();
        $this->postJson(route('finance.transition.post'), array_replace($this->input($entry), ['confirmed' => true]))->assertForbidden();
        $this->assertSame(0, JournalEntry::count());
    }
}
