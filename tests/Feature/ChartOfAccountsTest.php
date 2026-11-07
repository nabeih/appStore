<?php

namespace Tests\Feature;

use App\Actions\Finance\ChartOfAccounts;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Inventory\InventoryValuationSummary;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Sales\SalesTestCase;

class ChartOfAccountsTest extends SalesTestCase
{
    private function input(array $changes = []): array
    {
        return array_replace(['code' => 'CUSTOM-100', 'name' => 'حساب اختباري', 'account_group' => 'asset', 'normal_balance' => 'debit',
            'parent_id' => null, 'branch_id' => null, 'is_posting' => false, 'is_active' => true, 'reason' => 'اختبار هيكل الحساب'], $changes);
    }

    public function test_tree_reuses_existing_accounts_without_writing_or_exposing_cost_to_cashier(): void
    {
        $chart = app(ChartOfAccounts::class);
        $counts = [FinancialAccount::count(), JournalEntry::count(), DB::table('audit_events')->count()];
        $this->get('/finance/chart')->assertOk()->assertSee('شجرة الحسابات')->assertSee('inventory')->assertSee('الأصول');
        $this->get('/finance/chart?q=inventory')->assertOk()->assertSee('inventory')->assertDontSee('ACC-NONEXISTENT');
        $chart->tree($chart->accounts($this->owner));
        $chart->tree($chart->accounts($this->owner));
        $this->assertSame($counts, [FinancialAccount::count(), JournalEntry::count(), DB::table('audit_events')->count()]);
        $cashier = User::factory()->create(['role_id' => SalesAccess::provisionRole('sales_cashier')->id, 'branch_id' => $this->branchId]);
        $this->actingAs($cashier)->get('/finance/chart')->assertForbidden();
        $this->post('/finance/chart', $this->input())->assertForbidden();
        $this->get('/dashboard')->assertOk()->assertDontSee('قيمة المخزون بالتكلفة');
        $this->get('/finance/inventory-reconciliation')->assertForbidden();
    }

    public function test_create_child_duplicate_cycles_and_group_or_branch_mismatch_are_guarded(): void
    {
        $chart = app(ChartOfAccounts::class);
        $root = $chart->save($this->input(), $this->owner);
        $child = $chart->save($this->input(['code' => 'CUSTOM-101', 'parent_id' => $root->id, 'is_posting' => true]), $this->owner);
        $this->assertSame($root->id, $child->parent_id);
        $this->assertFalse($child->is_protected);
        $this->get('/finance/chart?q=CUSTOM-101')->assertOk()->assertSee('CUSTOM-100')->assertSee('CUSTOM-101');
        foreach ([
            $this->input(['code' => 'CUSTOM-101']),
            $this->input(['code' => 'BAD-GROUP', 'parent_id' => $root->id, 'account_group' => 'liability']),
            $this->input(['code' => 'BAD-POSTING-PARENT', 'parent_id' => $child->id]),
        ] as $input) {
            $this->post('/finance/chart', $input)->assertSessionHasErrors('account');
        }
        $this->put('/finance/chart/'.$root->id, $this->input(['parent_id' => $root->id]))->assertSessionHasErrors('account');
        $this->put('/finance/chart/'.$root->id, $this->input(['parent_id' => $child->id]))->assertSessionHasErrors('account');
        $this->assertNull($root->fresh()->parent_id);
        $this->put('/finance/chart/'.$child->id, $this->input(['code' => $child->code, 'parent_id' => $root->id, 'is_posting' => true, 'is_active' => false]))->assertRedirect();
        $this->assertFalse($child->fresh()->is_active);
    }

    public function test_aggregate_posting_is_rejected_before_creating_an_entry_and_database_trigger_also_guards_it(): void
    {
        $root = app(ChartOfAccounts::class)->save($this->input(), $this->owner);
        $before = JournalEntry::count();
        try {
            OperationContext::run(fn () => app(PostJournalAction::class)->execute($this->cash, 'aggregate-denied', $this->branchId, $this->owner,
                OperationContext::today(), [['account' => $root->id, 'amount' => 100], ['account' => 'opening_equity', 'amount' => -100]], 'اختبار حساب تجميعي'));
            $this->fail('Aggregate posting must fail.');
        } catch (ValidationException) {
            $this->assertSame($before, JournalEntry::count());
        }
        $entry = JournalEntry::create(['source_type' => FinancialAccount::class, 'source_id' => $root->id, 'event' => 'trigger-test',
            'branch_id' => $this->branchId, 'created_by' => $this->owner->id, 'transaction_date' => OperationContext::today(), 'description' => 'اختبار حاجز قاعدة البيانات']);
        $this->expectException(QueryException::class);
        DB::table('journal_lines')->insert(['journal_entry_id' => $entry->id, 'financial_account_id' => $root->id, 'debit_minor' => 100, 'credit_minor' => 0]);
    }

    public function test_posting_balances_once_and_historical_classification_and_system_accounts_are_protected(): void
    {
        $chart = app(ChartOfAccounts::class);
        $data = $this->input(['is_posting' => true]);
        $account = $chart->save($data, $this->owner);
        $post = fn () => app(PostJournalAction::class)->execute($account, 'one-only', $this->branchId, $this->owner, OperationContext::today(),
            [['account' => $account->id, 'amount' => 1250], ['account' => 'opening_equity', 'amount' => -1250]], 'قيد اختبار');
        $entry = OperationContext::run($post);
        $again = OperationContext::run($post);
        $this->assertSame($entry->id, $again->id);
        $this->assertSame('0', (string) $entry->lines()->selectRaw('SUM(debit_minor)-SUM(credit_minor) as balance')->value('balance'));
        $this->put('/finance/chart/'.$account->id, array_replace($data, ['is_active' => false]))->assertSessionHasErrors('account');
        $this->put('/finance/chart/'.$account->id, array_replace($data, ['account_group' => 'expense']))->assertSessionHasErrors('account');
        $system = FinancialAccount::where('code', 'inventory')->firstOrFail();
        $this->put('/finance/chart/'.$system->id, $this->input(['code' => 'NEW-INVENTORY', 'is_posting' => true]))->assertSessionHasErrors('account');
        $this->get('/finance/chart/'.$account->id)->assertOk()->assertSee('12.50');
    }

    public function test_inventory_value_uses_authoritative_values_including_reserved_stock_and_compares_ledger_without_writes(): void
    {
        $a = $this->stock('99.00', '20.00', 10, 'VALUE-A');
        $this->stock('500.00', '35.00', 3, 'VALUE-B');
        DB::table('inventories')->where('product_variant_id', $a->id)->update(['reserved_quantity' => 4]);
        $counts = [JournalEntry::count(), DB::table('stock_movements')->count()];
        $report = app(InventoryValuationSummary::class)->build($this->owner);
        $this->assertSame(30500, $report['value_minor']);
        $this->assertSame(30500, $report['reconciliation']['inventory']['stock_minor']);
        $this->assertSame(30500, $report['reconciliation']['inventory']['ledger_minor']);
        $this->assertSame(0, $report['reconciliation']['inventory']['difference_minor']);
        $this->get('/dashboard')->assertOk()->assertSee('قيمة المخزون بالتكلفة')->assertSee('305.00');
        $this->get('/finance/inventory-reconciliation')->assertOk()->assertSee('VALUE-A');
        $this->assertSame($counts, [JournalEntry::count(), DB::table('stock_movements')->count()]);
        DB::table('inventories')->where('product_variant_id', $a->id)->update(['value_minor' => 22500]);
        $report = app(InventoryValuationSummary::class)->build($this->owner);
        $this->assertSame(2500, $report['reconciliation']['inventory']['difference_minor']);
        $this->assertFalse($report['complete']);
        $this->assertSame(1, $report['history_issues']);
        DB::table('inventories')->where('product_variant_id', $a->id)->update(['value_minor' => null]);
        $report = app(InventoryValuationSummary::class)->build($this->owner);
        $this->assertNull($report['value_minor']);
        $this->assertNull($report['reconciliation']['inventory']['difference_minor']);
        $this->assertSame(1, $report['unknown_rows']);
    }

    public function test_branch_scope_and_cost_permissions_apply_to_dashboard_chart_direct_ids_and_ledger(): void
    {
        $this->stock('75.00', '20.00', 10, 'BRANCH-A');
        $other = DB::table('branches')->insertGetId(['name' => 'فرع محظور']);
        $foreign = FinancialAccount::create(['code' => 'FOREIGN-ACCOUNT', 'name' => 'حساب فرع محظور', 'type' => 'cash', 'branch_id' => $other]);
        $role = Role::create(['name' => 'chart_reader', 'display_name' => 'قارئ محاسبي', 'is_active' => true]);
        $role->permissions()->sync(Permission::whereIn('name', ['finance.view', 'sales.view_cost', 'inventory.view'])->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $this->branchId]);
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('200.00');
        foreach (['/dashboard', '/finance/chart', '/finance/inventory-reconciliation'] as $url) {
            $this->get($url.'?branch_id='.$other)->assertForbidden();
        }
        $this->get('/finance/chart/'.$foreign->id)->assertNotFound();
        $this->get('/finance/chart')->assertOk()->assertDontSee('حساب فرع محظور');
        $this->post('/finance/chart', $this->input())->assertForbidden();
        $role->permissions()->detach(Permission::where('name', 'finance.view')->value('id'));
        $user->unsetRelation('role');
        $restricted = app(InventoryValuationSummary::class)->build($user);
        $this->assertSame(20000, $restricted['value_minor']);
        $this->assertSame([], $restricted['reconciliation']);
        $this->get('/dashboard')->assertOk()->assertSee('200.00')->assertDontSee('رصيد الدفتر');
        $role->permissions()->detach(Permission::where('name', 'sales.view_cost')->value('id'));
        $user->unsetRelation('role');
        $this->get('/dashboard')->assertOk()->assertDontSee('قيمة المخزون بالتكلفة');
        $this->get('/finance/chart')->assertForbidden();
    }

    public function test_valuation_query_count_is_constant_when_variants_increase(): void
    {
        $this->stock('30', '10', 2, 'QUERY-ONE');
        $summary = app(InventoryValuationSummary::class);
        $this->owner->load('role.permissions');
        DB::enableQueryLog();
        $summary->build($this->owner);
        $one = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 12; $i++) {
            $this->stock('80', '20', 1, 'QUERY-'.$i);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $summary->build($this->owner);
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($one, $many);
        $this->assertLessThanOrEqual(4, $many);
    }

    public function test_quarantine_owned_value_and_invalid_empty_quantity_are_not_silently_ignored(): void
    {
        $variant = $this->stock('100', '20', 10, 'QUARANTINE');
        DB::table('inventories')->where('product_variant_id', $variant->id)->update(['quarantined_quantity' => 2, 'quarantined_value_minor' => 1500]);
        StockMovement::create(['branch_id' => $this->branchId, 'product_variant_id' => $variant->id, 'user_id' => $this->owner->id,
            'movement_type' => 'sale_return', 'quantity_change' => 2, 'unit_cost' => '7.50', 'value_change_minor' => 1500,
            'stock_bucket' => 'quarantine', 'effective_date' => OperationContext::today(), 'source_type' => FinancialAccount::class, 'source_id' => $this->cash->id]);
        $report = app(InventoryValuationSummary::class)->build($this->owner);
        $this->assertSame(21500, $report['value_minor']);
        $this->assertSame(1500, $report['reconciliation']['quarantine']['stock_minor']);
        $this->assertSame(0, $report['history_issues']);
        DB::table('inventories')->where('product_variant_id', $variant->id)->update(['quantity' => 0]);
        $report = app(InventoryValuationSummary::class)->build($this->owner);
        $this->assertNull($report['value_minor']);
        $this->assertSame(1, $report['invalid_rows']);
        $this->assertFalse($report['complete']);
    }
}
