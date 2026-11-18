<?php

namespace Tests\Feature;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Inventory\ProgressiveOperation;
use App\Actions\Sales\SaveSaleAction;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Support\Guidance\OwnerOnboarding;
use App\Support\Sales\SalesAccess;
use Database\Factories\BranchFactory;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sales\SalesTestCase;

class OwnerOnboardingTest extends SalesTestCase
{
    protected bool $salesFixtureStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner->forceFill(['branch_id' => $this->branchId])->save();
        DB::table('application_installations')->where('id', 1)->update(['status' => 'completed', 'completed_at' => now(),
            'owner_id' => $this->owner->id, 'first_branch_required' => false]);
    }

    private function guide(): array
    {
        return app(OwnerOnboarding::class)->build($this->owner, Branch::findOrFail($this->branchId));
    }

    public function test_new_owner_gets_real_progress_without_writes_or_losing_dashboard_statistics(): void
    {
        DB::table('sales_controls')->where('id', 1)->update(['activated_at' => null]);
        $before = $this->snapshot();
        $response = $this->get('/dashboard')->assertOk()->assertSee('أهلًا بك! جهّز متجرك لأول بيع')->assertSee('ما الذي ينقصك قبل البيع؟')
            ->assertSee('فواتير اليوم المعتمدة')->assertSee('آخر فواتير المبيعات المعتمدة')->assertSee('owner-onboarding.css');
        $guide = $response->viewData('onboarding');
        $this->assertSame(16, $guide['percent']);
        $this->assertFalse($guide['ready']);
        $this->assertSame('accounts', $guide['next']['key']);
        $this->assertNull(collect($guide['steps'])->firstWhere('key', 'sale')['url']);
        $this->assertTrue(collect($guide['steps'])->firstWhere('key', 'suppliers')['optional']);
        $this->get('/dashboard')->assertOk();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_approved_stock_and_documented_zero_advance_progress_without_enabling_sales(): void
    {
        $this->stock();
        $this->assertSame(50, $this->guide()['percent']);
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '0.00', 'reason' => 'رصيد بداية موثق'], $this->owner);
        $guide = $this->guide();
        $this->assertSame(66, $guide['percent']);
        $this->assertSame('cashier', $guide['next']['key']);
        $this->assertFalse($guide['ready']);
        $this->assertSame('setup', $guide['state']);
        $this->assertNull(Branch::findOrFail($this->branchId)->opening_stock_closed_at);
        $this->assertDatabaseMissing('audit_events', ['action' => 'operation.progressive_started']);
    }

    public function test_actual_progressive_activation_is_recognized_without_closing_setup(): void
    {
        $this->stock();
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '0', 'reason' => 'رصيد بداية موثق'], $this->owner);
        $operation = app(ProgressiveOperation::class);
        $branch = Branch::findOrFail($this->branchId);
        $report = $operation->report($branch);
        $operation->activate($branch, ['revision' => $report['revision'], 'confirmed' => true, 'external_movements_confirmed' => true, 'reason' => 'تشغيل اختباري صريح'], $this->owner);
        $guide = $this->guide();
        $this->assertTrue($guide['ready']);
        $this->assertSame('progressive', $guide['state']);
        $this->assertSame(83, $guide['percent']);
        $this->assertNull($branch->fresh()->opening_stock_closed_at);
        $this->assertNotNull(collect($guide['steps'])->firstWhere('key', 'sale')['url']);
    }

    public function test_only_approved_sale_completes_journey_and_turns_it_into_readiness_summary(): void
    {
        $variant = $this->stock();
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '0', 'reason' => 'رصيد بداية موثق'], $this->owner);
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => now()]);
        app(SaveSaleAction::class)->execute($this->saleData($variant), $this->owner);
        $this->assertFalse($this->guide()['summary']);
        $this->assertSame(83, $this->guide()['percent']);
        $this->sale($variant);
        $guide = $this->guide();
        $this->assertSame(100, $guide['percent']);
        $this->assertTrue($guide['summary']);
        $this->assertNull($guide['next']);
        $this->get('/dashboard')->assertOk()->assertSee('ملخص جاهزية متجرك')->assertDontSee('أهلًا بك! جهّز متجرك لأول بيع');
        app(ManageFinancialAccountsAction::class)->setActive($this->cash, false, 'اختبار تراجع الجاهزية', $this->owner);
        $this->assertTrue($this->guide()['summary']);
        $this->assertFalse($this->guide()['ready']);
        $this->get('/dashboard')->assertOk()->assertSee('ما الذي ينقصك قبل البيع؟');
    }

    public function test_cashier_and_owner_without_readiness_permission_do_not_receive_onboarding_payload(): void
    {
        $cashier = User::factory()->create(['role_id' => SalesAccess::provisionRole('sales_cashier')->id, 'branch_id' => $this->branchId]);
        $response = $this->actingAs($cashier)->get('/dashboard')->assertOk()->assertDontSee('data-owner-onboarding', false);
        $this->assertNull($response->viewData('onboarding'));
        $this->owner->role->permissions()->detach(Permission::where('name', 'sales.view_cost')->value('id'));
        $owner = $this->owner->fresh();
        $response = $this->actingAs($owner)->get('/dashboard')->assertOk()->assertDontSee('data-owner-onboarding', false);
        $this->assertNull($response->viewData('onboarding'));
    }

    public function test_revoked_account_permission_hides_link_and_server_still_denies_access(): void
    {
        $this->owner->role->permissions()->detach(Permission::where('name', 'sales.manage_accounts')->value('id'));
        $this->owner = $this->owner->fresh();
        $this->actingAs($this->owner);
        $guide = $this->get('/dashboard')->assertOk()->viewData('onboarding');
        $this->assertNull(collect($guide['steps'])->firstWhere('key', 'accounts')['url']);
        $this->get('/finance/accounts')->assertForbidden();
    }

    public function test_other_branch_stock_accounts_and_sales_do_not_complete_selected_branch(): void
    {
        $variant = $this->stock();
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '0', 'reason' => 'رصيد بداية موثق'], $this->owner);
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => now()]);
        $this->sale($variant);
        $other = BranchFactory::new()->create();
        $guide = $this->get('/dashboard?branch_id='.$other->id)->assertOk()->viewData('onboarding');
        $this->assertSame($other->id, $guide['branch_id']);
        $this->assertFalse($guide['summary']);
        foreach (['accounts', 'stock', 'cashier', 'sale'] as $key) {
            $this->assertFalse(collect($guide['steps'])->firstWhere('key', $key)['complete']);
        }
        $this->owner->role->permissions()->detach(Permission::where('name', 'sales.all_branches')->value('id'));
        $this->actingAs($this->owner->fresh())->get('/dashboard?branch_id='.$other->id)->assertForbidden();
    }

    public function test_missing_installation_state_is_not_repaired_or_shown_as_a_new_installation(): void
    {
        DB::table('application_installations')->where('id', 1)->update(['status' => 'blocked']);
        $this->assertNull(app(OwnerOnboarding::class)->build($this->owner, Branch::findOrFail($this->branchId)));
        $this->assertDatabaseHas('application_installations', ['id' => 1, 'status' => 'blocked']);
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['financial_accounts', 'journal_entries', 'journal_lines', 'inventories', 'stock_movements', 'sale_invoices', 'audit_events', 'sales_controls', 'application_installations', 'product_code_counters'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $result;
    }
}
