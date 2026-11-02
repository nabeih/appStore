<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\FinanceReports;
use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\PostJournalAction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Database\Factories\BranchFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Sales\SalesTestCase;

class FinancialReportSecurityTest extends SalesTestCase
{
    private const PRIVATE_KEYS = ['cogs_minor', 'gross_profit_minor', 'inventory_loss_minor', 'result_minor', 'gross_margin_percent', 'operating_margin_percent'];

    protected function setUp(): void
    {
        parent::setUp();
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '0', 'reason' => 'جرد بداية اختبار التقرير'], $this->owner);
    }

    /** @param array<string, int> $balances */
    private function seedReport(array $balances, ?string $date = null): void
    {
        $lines = [];
        foreach ($balances as $account => $amount) {
            $lines[] = ['account' => $account, 'amount' => $amount];
        }
        $lines[] = ['account' => 'owner_equity', 'amount' => -array_sum($balances)];
        OperationContext::run(fn () => app(PostJournalAction::class)->execute($this->cash, 'rpt-'.bin2hex(random_bytes(8)), $this->branchId, $this->owner,
            $date ?? OperationContext::today(), $lines, 'قيد اختبار التقرير المعزول'));
    }

    private function financeUser(array $permissions = ['finance.view']): User
    {
        $role = Role::create(['name' => 'report_'.Str::uuid(), 'display_name' => 'قارئ مالي', 'is_active' => true]);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create(['role_id' => $role->id, 'branch_id' => $this->branchId]);
    }

    private function reportUrl(array $extra = []): string
    {
        return route('finance.treasury.index', $extra + ['branch_id' => $this->branchId, 'from' => OperationContext::today(), 'to' => OperationContext::today()]);
    }

    public function test_finance_only_role_receives_no_cost_profit_or_margin_in_service_html_json_or_export(): void
    {
        $this->seedReport(['sales' => -20000, 'sales_returns' => 2500, 'cogs' => 10000, 'operating_expenses' => 3000, 'inventory_loss' => 500, 'cash_variance' => 200]);
        $user = $this->financeUser();
        $service = app(FinanceReports::class)->periodFor($user, ['branch_id' => $this->branchId, 'from' => OperationContext::today(), 'to' => OperationContext::today()]);
        foreach (self::PRIVATE_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $service);
        }
        $this->assertSame(17500, $service['net_sales_minor']);
        $this->assertSame(3000, $service['expenses_minor']);
        $html = $this->actingAs($user)->get($this->reportUrl())->assertOk()->assertDontSee('تكلفة البضاعة المباعة')->assertDontSee('مجمل الربح')->assertDontSee('هامش النتيجة');
        foreach (self::PRIVATE_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $html->viewData('period'));
        }
        foreach ([$this->reportUrl(['include_cost' => 1]), $this->reportUrl(['export' => 1])] as $url) {
            $response = $this->getJson($url)->assertOk();
            foreach (self::PRIVATE_KEYS as $key) {
                $response->assertJsonMissingPath('period.'.$key);
            }
            $response->assertJsonPath('period.net_sales_minor', 17500);
        }
        $this->get($this->reportUrl(['export' => 1]))->assertHeader('Content-Disposition', 'attachment; filename="operating-period-report.json"');
    }

    public function test_authorized_owner_sees_exact_profit_and_percentages_without_any_ledger_mutation(): void
    {
        $this->seedReport(['sales' => -20000, 'sales_returns' => 2500, 'cogs' => 10000, 'operating_expenses' => 3000, 'inventory_loss' => 500, 'cash_variance' => 200]);
        $before = DB::table('journal_lines')->orderBy('id')->get()->toJson();
        $response = $this->getJson($this->reportUrl())->assertOk()->assertJsonPath('period.net_sales_minor', 17500)
            ->assertJsonPath('period.cogs_minor', 10000)->assertJsonPath('period.gross_profit_minor', 7500)
            ->assertJsonPath('period.result_minor', 3800)->assertJsonPath('period.gross_margin_percent', '42.86')
            ->assertJsonPath('period.operating_margin_percent', '21.71');
        $this->get($this->reportUrl())->assertOk()->assertSee('نتيجة الفترة التشغيلية')->assertSee('42.86%')->assertSee('21.71%')->assertSee('ليست صافي الربح المحاسبي الشامل');
        $this->getJson($this->reportUrl(['export' => 1]))->assertJsonPath('period', $response->json('period'));
        $this->assertSame($before, DB::table('journal_lines')->orderBy('id')->get()->toJson());
    }

    /** @return array<string, array{array<string, int>, int, int, ?string, ?string}> */
    public static function signedPeriods(): array
    {
        return [
            'zero net sales' => [['sales' => -5000, 'sales_returns' => 5000, 'cogs' => 2000, 'operating_expenses' => 500], -2000, -2500, null, null],
            'negative net sales' => [['sales_returns' => 5000, 'cogs' => -3000, 'operating_expenses' => 500], -2000, -2500, '40.00', '50.00'],
            'negative profit' => [['sales' => -5000, 'cogs' => 6000, 'operating_expenses' => 500], -1000, -1500, '-20.00', '-30.00'],
        ];
    }

    #[DataProvider('signedPeriods')]
    public function test_zero_and_signed_periods_are_calculated_without_float_or_division_by_zero(array $balances, int $gross, int $result, ?string $grossMargin, ?string $operatingMargin): void
    {
        $this->seedReport($balances);
        $this->getJson($this->reportUrl())->assertOk()->assertJsonPath('period.gross_profit_minor', $gross)->assertJsonPath('period.result_minor', $result)
            ->assertJsonPath('period.gross_margin_percent', $grossMargin)->assertJsonPath('period.operating_margin_percent', $operatingMargin);
        if ($grossMargin === null) {
            $this->get($this->reportUrl())->assertSee('لا ينطبق: صافي المبيعات صفر');
        } elseif ($grossMargin === '40.00') {
            $this->get($this->reportUrl())->assertSee('صافي المبيعات سالب');
        }
    }

    public function test_period_filters_foreign_branch_and_missing_finance_permission_are_enforced_for_json_and_export(): void
    {
        $this->seedReport(['sales' => -5000, 'cogs' => 2000]);
        $this->seedReport(['sales' => -90000, 'cogs' => 70000], now(config('sales.timezone'))->subDay()->toDateString());
        $this->getJson($this->reportUrl())->assertOk()->assertJsonPath('period.net_sales_minor', 5000)->assertJsonPath('period.cogs_minor', 2000);
        $other = BranchFactory::new()->create();
        $user = $this->financeUser(['finance.view', 'sales.view_cost']);
        $this->actingAs($user)->getJson($this->reportUrl(['branch_id' => $other->id]))->assertForbidden();
        $this->get($this->reportUrl(['branch_id' => $other->id, 'export' => 1]))->assertForbidden();
        session()->forget('password_hash_web');
        $noFinance = $this->financeUser(['sales.view_cost']);
        $this->actingAs($noFinance)->getJson($this->reportUrl())->assertForbidden();
    }

    public function test_incomplete_sources_keep_calculated_percentages_clearly_unapproved_in_html_and_json(): void
    {
        $this->seedReport(['sales' => -10000, 'cogs' => 4000]);
        DB::table('financial_accounts')->insert(['code' => 'UNCONFIRMED', 'name' => 'بداية مجهولة', 'type' => 'wallet', 'branch_id' => $this->branchId]);
        $this->getJson($this->reportUrl())->assertOk()->assertJsonPath('completeness.complete', false);
        $this->get($this->reportUrl())->assertOk()->assertSee('غير معتمد')->assertDontSee('60.00%');
    }

    public function test_related_cost_pages_apis_and_existing_exports_do_not_bypass_cost_permission(): void
    {
        $variant = $this->stock();
        $user = $this->financeUser(['finance.view', 'catalog.search', 'inventory.view', 'sales.view', 'sales.sell', 'sales.customers', 'sales.collect',
            'inventory.valuation_review', 'finance.transition_review', 'sales.reports', 'purchases.view']);
        $this->actingAs($user);
        foreach ([route('sales.reports'), route('inventory.review.index'), route('inventory.review.export'), route('finance.transition.index', ['export' => 1]), route('purchases.invoices.index')] as $url) {
            $this->get($url)->assertForbidden();
        }
        foreach (['/api/inventories', '/api/products', route('sales.catalog', ['branch_id' => $this->branchId]), route('search.products', ['q' => $variant->sku])] as $url) {
            $response = $this->getJson($url)->assertOk();
            $json = json_encode($response->json(), JSON_THROW_ON_ERROR);
            foreach (['average_cost', 'value_minor', 'unit_cost', 'cost_minor', 'gross_profit'] as $key) {
                $this->assertStringNotContainsString('"'.$key.'"', $json);
            }
            if (str_contains($url, '/sales/catalog')) {
                $response->assertJsonPath('data.0.variants.0.minimum_selling_price', null);
            } else {
                $this->assertStringNotContainsString('"minimum_selling_price"', $json);
            }
        }
        $this->get(route('inventory.products.show', $variant->product_id))->assertOk()->assertDontSee('متوسط التكلفة');
    }
}
