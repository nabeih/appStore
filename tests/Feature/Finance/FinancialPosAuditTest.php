<?php

namespace Tests\Feature\Finance;

use App\Actions\Branches\CreateBranchAction;
use App\Actions\Finance\FinanceReports;
use App\Actions\Inventory\SetupReadinessReport;
use App\Models\Branch;
use App\Models\CashCount;
use App\Models\Category;
use App\Models\Color;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Inventory;
use App\Models\Permission;
use App\Models\ProductVariant;
use App\Models\PurchaseInvoice;
use App\Models\Role;
use App\Models\SaleExchange;
use App\Models\SaleInvoice;
use App\Models\SaleReturn;
use App\Models\Size;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialPosAuditTest extends TestCase
{
    private string $original;

    private string $auditDatabase;

    private User $owner;

    private Branch $branch;

    private FinancialAccount $cash;

    private array $evidence = [];

    private array $pages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $source = DB::connection();
        if (! app()->environment('testing') || $source->getDriverName() !== 'mysql' || $source->getDatabaseName() !== 'appstore_golive_20261031_tests'
            || $source->getConfig('host') !== '127.0.0.1' || $source->getConfig('url') || app()->configurationIsCached()) {
            throw new \LogicException('Audit fixtures require the guarded isolated loopback source.');
        }
        $this->original = DB::getDefaultConnection();
        $this->auditDatabase = 'appstore_financial_audit_'.bin2hex(random_bytes(8));
        $source->statement('CREATE DATABASE '.$this->auditDatabase.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.financial_audit' => array_replace($source->getConfig(), ['database' => $this->auditDatabase, 'name' => 'financial_audit', 'url' => null])]);
        DB::setDefaultConnection('financial_audit');
        $target = DB::connection();
        $target->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($source->getSchemaBuilder()->getTableListing($source->getDatabaseName(), false) as $table) {
            $ddl = (array) $source->selectOne('SHOW CREATE TABLE '.$source->getQueryGrammar()->wrapTable($table));
            $target->statement($ddl['Create Table']);
        }
        $target->statement('SET FOREIGN_KEY_CHECKS=1');
        DB::table('product_code_counters')->insert(['id' => 1, 'last_number' => 0, 'lock_version' => 0]);
        DB::table('sales_controls')->insert(['id' => 1]);
        DB::table('application_installations')->insert(['id' => 1, 'status' => 'blocked']);
        $this->travelTo(Carbon::parse('2026-10-15 12:00:00', 'Asia/Hebron'));
        $this->owner = User::factory()->salesOwner()->create(['username' => 'audit.owner', 'password' => 'Isolated-Audit!'.bin2hex(random_bytes(12))]);
        $this->branch = app(CreateBranchAction::class)->execute(['name' => 'فرع التدقيق المعزول']);
        $this->actingAs($this->owner);
        $this->artisan('app:sales-setup', ['--owner' => $this->owner->username])->assertSuccessful();
        $this->cash = FinancialAccount::where('code', 'CASH-'.$this->branch->id)->firstOrFail();
        $this->opening($this->cash, '1000');
    }

    protected function tearDown(): void
    {
        if (isset($this->original)) {
            DB::purge('financial_audit');
            DB::setDefaultConnection($this->original);
        }
        parent::tearDown();
    }

    private function opening(FinancialAccount $account, string $amount): void
    {
        $this->postJson(route('finance.accounts.opening', $account), ['opening_balance' => $amount, 'confirm_opening' => true, 'reason' => 'جرد بداية اختبار موثق'])->assertOk();
    }

    private function check(string $name, int $expected, int $actual): void
    {
        $this->evidence[$name] = ['expected' => $expected, 'actual' => $actual, 'difference' => $actual - $expected];
        $this->assertSame($expected, $actual, $name);
    }

    private function balance(string $code): int
    {
        return (int) DB::table('journal_lines as l')->join('financial_accounts as a', 'a.id', '=', 'l.financial_account_id')
            ->where('a.code', $code)->selectRaw('COALESCE(SUM(l.debit_minor),0)-COALESCE(SUM(l.credit_minor),0) AS value')->value('value');
    }

    private function page(string $key, string $url): void
    {
        $response = $this->get($url);
        $this->pages[$key] = ['url' => parse_url($url, PHP_URL_PATH), 'status' => $response->status()];
        $response->assertOk();
        File::ensureDirectoryExists(storage_path('app/reports/financial-audit-preview'));
        file_put_contents(storage_path('app/reports/financial-audit-preview/'.$key.'.html'), $response->getContent());
    }

    public function test_independent_october_scenario_through_http_reconciles_stock_cash_debts_and_results(): void
    {
        /** Independently calculated before any trading: monetary amounts are minor units. */
        $expected = ['purchase_total' => 30000, 'weighted_a_unit_cost' => 2500, 'sale_gross' => 20000, 'sale_discount' => 3800, 'sale_net' => 16200,
            'sale_cost' => 12000, 'initial_due' => 10000, 'due_after_collection' => 6000, 'return_value' => 3150, 'return_cost' => 2500,
            'due_after_return' => 2850, 'exchange_received' => 5050, 'a_quantity' => 16, 'a_value' => 40000, 'b_quantity' => 9, 'b_value' => 9000,
            'inventory_value' => 49000, 'sales' => 20200, 'returns' => 4950, 'net_sales' => 15250, 'cogs' => 11000, 'gross_profit' => 4250,
            'expenses' => 3000, 'cash_variance' => 250, 'result' => 1000, 'cash' => 93000, 'bank' => 10000, 'supplier_due' => 15000,
            'customer_due' => 0, 'customer_credit' => 0, 'expense_due' => 1000, 'journal_debits' => 253650, 'journal_credits' => 253650];
        $day = OperationContext::today();
        foreach (['bank' => 'بنك التدقيق', 'wallet' => 'محفظة التدقيق'] as $type => $name) {
            $this->postJson(route('finance.accounts.store'), ['branch_id' => $this->branch->id, 'name' => $name, 'type' => $type])->assertSuccessful();
            $this->opening(FinancialAccount::where('type', $type)->firstOrFail(), '0');
        }
        $bank = FinancialAccount::where('type', 'bank')->firstOrFail();
        $category = Category::create(['name' => 'ملابس التدقيق']);
        $color = Color::create(['name' => 'أسود', 'code' => 'BLK']);
        $size = Size::create(['name' => 'L', 'code' => 'L']);
        $rows = [];
        foreach ([['A', 'الصنف أ', '20', '40'], ['B', 'الصنف ب', '10', '20']] as [$code, $name, $cost, $price]) {
            $rows[] = ['name' => $name, 'code' => $code, 'category_id' => $category->id, 'color_id' => $color->id, 'size_id' => $size->id, 'qty' => 10, 'cost' => $cost, 'price' => $price];
        }
        $this->postJson(route('inventory.opening-stock.store'), ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'opening_date' => $day, 'rows' => $rows])->assertCreated();
        $a = ProductVariant::whereHas('product', fn ($q) => $q->where('code', 'A'))->firstOrFail();
        $b = ProductVariant::whereHas('product', fn ($q) => $q->where('code', 'B'))->firstOrFail();
        $supplier = Supplier::factory()->create();
        $purchaseData = ['client_uuid' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'branch_id' => $this->branch->id, 'invoice_date' => $day,
            'initial_payment' => '100', 'payment_method' => 'cash', 'payment_details' => ['account_id' => $this->cash->id],
            'rows' => [['product_variant_id' => $a->id, 'quantity' => 10, 'unit_cost' => '30']]];
        $response = $this->postJson(route('purchases.invoices.store'), $purchaseData)->assertOk();
        $purchase = PurchaseInvoice::findOrFail($response->json('id'));
        $this->postJson(route('purchases.invoices.approve', $purchase), ['version' => $purchase->version])->assertOk();
        $this->check('purchase_total', $expected['purchase_total'], (int) $purchase->fresh()->total_minor);
        $this->check('weighted_a_unit_cost', $expected['weighted_a_unit_cost'], (int) round((float) Inventory::where('product_variant_id', $a->id)->value('average_cost') * 100));
        $this->postJson(route('purchases.payments.store', $purchase), ['client_uuid' => (string) Str::uuid(), 'account_id' => $this->cash->id, 'amount' => '50', 'payment_date' => $day])->assertSuccessful();
        $readiness = app(SetupReadinessReport::class)->build($this->branch);
        $this->assertTrue($readiness['can_close'], json_encode($readiness['blockers'], JSON_UNESCAPED_UNICODE));
        $this->postJson(route('inventory.review.close', $this->branch), ['revision' => $readiness['revision'], 'start_date' => $day,
            'reason' => 'اعتماد إعداد التدقيق المعزول', 'evidence' => 'مستندات وجرد البداية الاختبارية', 'confirmed' => true, 'accounts_confirmed' => true,
            'parties_confirmed' => true, 'account_scope_confirmed' => true])->assertOk();
        $customer = Customer::factory()->create();
        $saleData = ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'customer_id' => $customer->id,
            'payment_mode' => 'partial', 'payments' => [['account_id' => $this->cash->id, 'amount' => '62']], 'reason' => 'خصم التدقيق',
            'discount' => ['type' => 'amount', 'value' => '18'],
            'rows' => [['product_variant_id' => $a->id, 'quantity' => 4, 'discount' => ['type' => 'amount', 'value' => '20']], ['product_variant_id' => $b->id, 'quantity' => 2]]];
        $saleResponse = $this->postJson(route('sales.invoices.complete'), $saleData)->assertOk();
        $sale = SaleInvoice::findOrFail($saleResponse->json('id'));
        $this->check('sale_gross', $expected['sale_gross'], (int) $sale->gross_minor);
        $this->check('sale_discount', $expected['sale_discount'], (int) $sale->line_discount_minor + (int) $sale->invoice_discount_minor);
        $this->check('sale_net', $expected['sale_net'], (int) $sale->total_minor);
        $this->check('sale_cost', $expected['sale_cost'], (int) $sale->cost_minor);
        $this->check('initial_due', $expected['initial_due'], (int) $sale->due_minor);
        $this->postJson(route('customers.receipts.store'), ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'customer_id' => $customer->id, 'kind' => 'receipt',
            'allocations' => [['sale_invoice_id' => $sale->id, 'amount' => '40']], 'payments' => [['account_id' => $this->cash->id, 'amount' => '40']]])->assertSuccessful();
        $this->check('due_after_collection', $expected['due_after_collection'], (int) $sale->fresh()->due_minor);
        $itemA = $sale->items()->where('product_variant_id', $a->id)->firstOrFail();
        $itemB = $sale->items()->where('product_variant_id', $b->id)->firstOrFail();
        $this->postJson(route('sales.returns.store', $sale), ['client_uuid' => (string) Str::uuid(), 'reason' => 'إرجاع قطعة من الصنف أ',
            'rows' => [['sale_item_id' => $itemA->id, 'quantity' => 1, 'condition' => 'resellable']]])->assertOk();
        $returned = SaleReturn::firstOrFail();
        $this->check('return_value', $expected['return_value'], (int) $returned->total_minor);
        $this->check('return_cost', $expected['return_cost'], (int) $returned->cost_minor);
        $this->check('due_after_return', $expected['due_after_return'], (int) $sale->fresh()->due_minor);
        $this->postJson(route('sales.exchanges.store', $sale), ['client_uuid' => (string) Str::uuid(), 'reason' => 'استبدال الصنف ب بالأغلى وتسوية الدين',
            'return_rows' => [['sale_item_id' => $itemB->id, 'quantity' => 1, 'condition' => 'resellable']],
            'rows' => [['product_variant_id' => $a->id, 'quantity' => 1]], 'payments' => [['account_id' => $this->cash->id, 'amount' => '50.50']], 'settlement_mode' => 'paid'])->assertOk();
        $exchange = SaleExchange::firstOrFail();
        $this->check('exchange_received', $expected['exchange_received'], (int) DB::table('customer_receipts')->where('origin', 'exchange')->where('kind', 'receipt')->sum('amount_minor'));
        $this->postJson(route('finance.expenses.store'), ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'title' => 'مصروف التدقيق', 'category' => 'utilities',
            'period_start' => $day, 'period_end' => $day, 'transaction_date' => $day, 'amount' => '30'])->assertOk();
        $expense = Expense::firstOrFail();
        $this->postJson(route('finance.expenses.approve', $expense))->assertOk();
        $this->postJson(route('finance.expenses.pay', $expense), ['client_uuid' => (string) Str::uuid(), 'account_id' => $this->cash->id, 'amount' => '20', 'reason' => 'سداد جزئي موثق'])->assertOk();
        foreach ([['kind' => 'transfer', 'from_account_id' => $this->cash->id, 'to_account_id' => $bank->id, 'amount' => '100'],
            ['kind' => 'owner_contribution', 'to_account_id' => $this->cash->id, 'amount' => '50']] as $operation) {
            $this->postJson(route('finance.treasury.store'), $operation + ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'reason' => 'حركة تدقيق موثقة'])->assertOk();
        }
        $this->postJson(route('finance.counts.store'), ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'account_id' => $this->cash->id, 'actual' => '930', 'reason' => 'جرد فعلي للتدقيق'])->assertOk();
        $count = CashCount::firstOrFail();
        $this->check('cash_before_settlement', 93250, $this->balance($this->cash->code));
        $this->postJson(route('finance.counts.settle', $count), ['client_uuid' => (string) Str::uuid(), 'confirmed' => true, 'reason' => 'فرق مستقل بعد مراجعة كل المستندات'])->assertOk();
        $stocks = Inventory::where('branch_id', $this->branch->id)->get()->keyBy('product_variant_id');
        foreach (['a_quantity' => (int) $stocks[$a->id]->quantity, 'a_value' => (int) $stocks[$a->id]->value_minor,
            'b_quantity' => (int) $stocks[$b->id]->quantity, 'b_value' => (int) $stocks[$b->id]->value_minor,
            'inventory_value' => $this->balance('inventory'), 'cash' => $this->balance($this->cash->code), 'bank' => $this->balance($bank->code),
            'supplier_due' => -$this->balance('supplier_payables'), 'customer_due' => $this->balance('receivables'), 'customer_credit' => -$this->balance('customer_credit'),
            'expense_due' => -$this->balance('expense_payables'), 'journal_debits' => (int) DB::table('journal_lines')->sum('debit_minor'), 'journal_credits' => (int) DB::table('journal_lines')->sum('credit_minor')] as $name => $actual) {
            $this->check($name, $expected[$name], $actual);
        }
        $report = app(FinanceReports::class)->period(['branch_id' => $this->branch->id, 'from' => '2026-10-01', 'to' => '2026-10-31']);
        foreach (['sales' => 'sales_minor', 'returns' => 'returns_minor', 'net_sales' => 'net_sales_minor', 'cogs' => 'cogs_minor', 'gross_profit' => 'gross_profit_minor',
            'expenses' => 'expenses_minor', 'cash_variance' => 'variance_minor', 'result' => 'result_minor'] as $name => $key) {
            $this->check($name, $expected[$name], (int) $report[$key]);
        }
        $this->assertSame('27.87', $report['gross_margin_percent']);
        $this->assertSame('6.56', $report['operating_margin_percent']);
        $this->assertSame([], DB::table('journal_lines')->groupBy('journal_entry_id')->select('journal_entry_id')
            ->havingRaw('SUM(debit_minor) <> SUM(credit_minor)')->pluck('journal_entry_id')->all());
        foreach ($stocks as $stock) {
            $this->check('movement_quantity_'.$stock->product_variant_id, (int) $stock->quantity,
                (int) DB::table('stock_movements')->where('branch_id', $this->branch->id)->where('product_variant_id', $stock->product_variant_id)->where('stock_bucket', 'available')->sum('quantity_change'));
            $this->check('movement_value_'.$stock->product_variant_id, (int) $stock->value_minor,
                (int) DB::table('stock_movements')->where('branch_id', $this->branch->id)->where('product_variant_id', $stock->product_variant_id)->where('stock_bucket', 'available')->sum('value_change_minor'));
        }
        foreach (['cashier' => route('sales.cashier'), 'sales' => route('sales.invoices.index'), 'sale' => route('sales.invoices.show', $sale),
            'sale_print' => route('sales.invoices.print', $sale), 'return_form' => route('sales.returns.create', $sale), 'return' => route('sales.returns.show', $returned),
            'exchange_form' => route('sales.exchanges.create', $sale), 'exchange' => route('sales.exchanges.show', $exchange),
            'purchases' => route('purchases.invoices.index'), 'purchase_create' => route('purchases.invoices.create'), 'purchase' => route('purchases.invoices.show', $purchase),
            'suppliers' => route('suppliers.index'), 'supplier' => route('suppliers.show', $supplier), 'supplier_ledger' => route('finance.suppliers.show', $supplier),
            'customers' => route('customers.index'), 'customer' => route('customers.show', $customer), 'inventory' => route('inventory.index'),
            'opening' => route('inventory.opening-stock.index'), 'readiness' => route('inventory.review.index'),
            'expenses' => route('finance.expenses.index'), 'expense' => route('finance.expenses.show', $expense),
            'accounts' => route('finance.accounts.index'), 'treasury' => route('finance.treasury.index', ['branch_id' => $this->branch->id, 'from' => '2026-10-01', 'to' => '2026-10-31']),
            'sales_report' => route('sales.reports', ['branch_id' => $this->branch->id, 'from' => '2026-10-01', 'to' => '2026-10-31']),
            'transition' => route('finance.transition.index')] as $key => $url) {
            $this->page($key, $url);
        }
        File::ensureDirectoryExists(storage_path('app/reports'));
        file_put_contents(storage_path('app/reports/financial-remediation-numerical.json'), json_encode(['database' => $this->auditDatabase, 'date' => $day, 'checks' => $this->evidence,
            'pages' => $this->pages, 'gross_margin_percent' => $report['gross_margin_percent'], 'operating_margin_percent' => $report['operating_margin_percent'], 'production_margin_fields_present' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $browserPassword = 'Browser-Audit!'.bin2hex(random_bytes(16));
        $this->owner->forceFill(['password' => $browserPassword])->save();
        File::ensureDirectoryExists(storage_path('app/private/audits'));
        file_put_contents(storage_path('app/private/audits/financial-audit-browser.json'), json_encode(['database' => $this->auditDatabase, 'username' => $this->owner->username, 'password' => $browserPassword,
            'branch_id' => $this->branch->id, 'cash_id' => $this->cash->id, 'sale_id' => $sale->id, 'purchase_id' => $purchase->id, 'expense_id' => $expense->id], JSON_THROW_ON_ERROR));
        chmod(storage_path('app/private/audits/financial-audit-browser.json'), 0600);
    }

    public function test_treasury_requires_cost_permission_before_disclosing_cogs_and_profit(): void
    {
        $category = Category::create(['name' => 'صلاحية التكلفة']);
        $color = Color::create(['name' => 'أسود', 'code' => 'BLK']);
        $size = Size::create(['name' => 'L', 'code' => 'L']);
        $this->postJson(route('inventory.opening-stock.store'), ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'opening_date' => OperationContext::today(),
            'rows' => [['name' => 'صنف تكلفة سرية', 'code' => 'COST-AUDIT', 'category_id' => $category->id, 'color_id' => $color->id, 'size_id' => $size->id, 'qty' => 1, 'cost' => '20', 'price' => '35']]])->assertCreated();
        $review = app(SetupReadinessReport::class)->build($this->branch);
        $this->assertTrue($review['can_close']);
        $this->postJson(route('inventory.review.close', $this->branch), ['revision' => $review['revision'], 'start_date' => OperationContext::today(), 'reason' => 'تجهيز اختبار كشف التكلفة',
            'evidence' => 'مصادر اختبار معزول', 'confirmed' => true, 'accounts_confirmed' => true, 'parties_confirmed' => true, 'account_scope_confirmed' => true])->assertOk();
        $this->postJson(route('sales.invoices.complete'), ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'payment_mode' => 'paid',
            'rows' => [['product_variant_id' => ProductVariant::firstOrFail()->id, 'quantity' => 1]], 'payments' => [['account_id' => $this->cash->id, 'amount' => '35']]])->assertOk();
        $role = Role::create(['name' => 'finance_only_audit', 'display_name' => 'مالية دون تكلفة', 'is_active' => true]);
        $role->permissions()->sync(Permission::where('name', 'finance.view')->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $this->branch->id]);
        session()->forget('password_hash_web');
        $this->assertFalse($user->can('sales.view_cost'));
        $response = $this->actingAs($user)->get(route('finance.treasury.index', ['branch_id' => $this->branch->id]));
        file_put_contents(storage_path('app/reports/financial-remediation-cost-access.json'), json_encode(['database' => $this->auditDatabase, 'cost_permission' => false, 'actual_http_status' => $response->status(),
            'cost_label_exposed' => str_contains($response->getContent(), 'تكلفة المبيعات'), 'cogs_minor_exposed' => $response->status() === 200 ? ($response->viewData('period')['cogs_minor'] ?? null) : null, 'period_data_exposed' => $response->status() === 200], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $response->assertOk()->assertDontSee('تكلفة البضاعة المباعة')->assertDontSee('مجمل الربح');
        foreach (['cogs_minor', 'gross_profit_minor', 'inventory_loss_minor', 'result_minor', 'gross_margin_percent', 'operating_margin_percent'] as $key) {
            $this->assertArrayNotHasKey($key, $response->viewData('period'));
        }
    }
}
