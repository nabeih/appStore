<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\FinanceContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GoLiveSchemaTest extends TestCase
{
    public function test_financial_migration_on_the_exact_snapshot_preserves_data_and_opens_read_only_screens(): void
    {
        if (getenv('GO_LIVE_SNAPSHOT') !== '1') {
            $this->markTestSkipped('Snapshot-only smoke test; no fixtures or schema resets.');
        }
        $this->assertSame('appstore_golive_20261031_snapshot', DB::connection()->getDatabaseName());
        $this->assertTrue(FinanceContext::installed());
        foreach (['expenses', 'expense_payments', 'cash_counts', 'treasury_documents'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertSame(0, DB::table($table)->count());
            $indexes = DB::select('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND NON_UNIQUE=0', [$table]);
            $this->assertNotEmpty($indexes);
            $keys = DB::select('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL', [$table]);
            $this->assertNotEmpty($keys);
        }
        foreach (['purchase_invoices' => ['financial_posted_at', 'payment_details'],
            'supplier_payments' => ['financial_account_id', 'status', 'financial_posted_at', 'reversed_at'],
            'journal_lines' => ['supplier_id', 'purchase_invoice_id', 'expense_id']] as $table => $columns) {
            $this->assertTrue(Schema::hasColumns($table, $columns));
        }
        $this->assertDatabaseHas('migrations', ['migration' => '2026_10_25_194950_integrate_supplier_expense_and_treasury_documents']);
        $fingerprint = fn (): string => hash('sha256', json_encode(collect(['financial_accounts', 'journal_entries', 'journal_lines', 'inventories', 'stock_movements',
            'opening_stock_entries', 'purchase_invoices', 'supplier_payments', 'audit_events', 'branches', 'products', 'product_variants', 'product_code_counters', 'product_images'])
            ->mapWithKeys(fn ($table): array => [$table => DB::table($table)->orderBy('id')->get()->toArray()])->all(), JSON_THROW_ON_ERROR));
        $before = $fingerprint();
        $this->actingAs(User::findOrFail(1));
        foreach (['finance.treasury.index', 'finance.expenses.index', 'finance.accounts.index', 'finance.transition.index', 'inventory.review.index', 'sales.reports'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('finance.treasury.index'))->assertDontSee('لم تُطبّق الجداول الجديدة')->assertSee('غير معتمدة');
        $this->assertSame($before, $fingerprint());
        $this->assertNull(DB::table('branches')->where('id', 1)->value('opening_stock_closed_at'));
        $this->assertSame(1, DB::table('journal_entries')->count());
    }
}
