<?php

namespace Tests\Feature\Sales;

use App\Actions\Customers\RecordCustomerReceiptAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Sales\ExchangeSaleAction;
use App\Actions\Sales\PostSaleAction;
use App\Actions\Sales\ReturnSaleAction;
use App\Actions\Sales\ReverseSalesOperationAction;
use App\Actions\Sales\SaveSaleAction;
use App\Models\CustomerReceipt;
use App\Models\Inventory;
use App\Models\User;
use App\Support\Inventory\Setup\InventoryState;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use App\Support\Sales\SalesReports;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SalesSafetyTest extends SalesTestCase
{
    public function test_cashier_catalog_preview_and_html_do_not_expose_costs_or_profit(): void
    {
        $variant = $this->stock();
        $cashier = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $this->actingAs($cashier);
        $catalog = $this->getJson(route('sales.catalog', ['branch_id' => $this->branchId]))->assertOk();
        $catalog->assertJsonPath('readiness.review_url', null);
        $this->assertArrayNotHasKey('unit_cost', $catalog->json('data.0.variants.0'));
        $preview = $this->postJson(route('sales.preview'), $this->saleData($variant))->assertOk();
        $this->assertArrayNotHasKey('cost', $preview->json());
        $this->assertArrayNotHasKey('gross_profit', $preview->json());
        $this->get(route('sales.cashier'))->assertOk()->assertDontSee('cashier-cost', false)->assertDontSee('cashier-gross-profit', false);
        $this->get(route('sales.reports'))->assertForbidden();
    }

    public function test_cashier_cannot_change_prices_exceed_discounts_or_sell_below_the_limit(): void
    {
        $variant = $this->stock();
        $cashier = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $this->actingAs($cashier);
        $data = $this->saleData($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => '34', 'price_reason' => 'تجاوز']]]);
        $this->postJson(route('sales.invoices.complete'), $data)->assertForbidden();
        $data = $this->saleData($variant, ['discount' => ['type' => 'percent', 'value' => '50']]);
        $this->postJson(route('sales.invoices.complete'), $data)->assertForbidden();
        $this->assertDatabaseCount('sale_invoices', 0);
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $variant->id, 'quantity' => 10]);
    }

    public function test_missing_or_foreign_customer_and_account_data_cannot_leave_unidentified_debt_or_wrong_branch_cash(): void
    {
        $variant = $this->stock();
        $this->postJson(route('sales.invoices.complete'), $this->saleData($variant, ['customer_id' => null, 'payment_mode' => 'credit', 'payments' => []]))->assertUnprocessable();
        $other = DB::table('branches')->insertGetId(['name' => 'فرع آخر']);
        $cashier = User::factory()->create(['branch_id' => $other, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $this->actingAs($cashier)->getJson(route('sales.catalog', ['branch_id' => $this->branchId]))->assertForbidden();
        $this->assertDatabaseCount('sale_invoices', 0);
    }

    public function test_invalid_amounts_client_cost_injection_and_over_return_are_rejected(): void
    {
        $variant = $this->stock();
        foreach (['NaN', 'INF', '-1', '1.001', '1e3'] as $amount) {
            $this->postJson(route('sales.preview'), $this->saleData($variant, ['discount' => ['type' => 'amount', 'value' => $amount]]))->assertUnprocessable();
        }
        $data = $this->saleData($variant);
        $data['rows'][0]['cost_minor'] = 0;
        $this->postJson(route('sales.invoices.complete'), $data)->assertUnprocessable();
        $this->assertDatabaseCount('customer_receipts', 0);
    }

    public function test_mid_post_failure_rolls_back_sale_stock_receipt_and_every_journal(): void
    {
        $variant = $this->stock();
        $draft = app(SaveSaleAction::class)->execute($this->saleData($variant), $this->owner);
        $journals = DB::table('journal_entries')->count();
        CustomerReceipt::created(function (): void {
            throw new RuntimeException('receipt failure');
        });
        try {
            app(PostSaleAction::class)->execute($draft, $this->owner);
            $this->fail('Expected posting failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('receipt failure', $error->getMessage());
        } finally {
            CustomerReceipt::flushEventListeners();
        }
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $variant->id, 'quantity' => 10, 'value_minor' => 20000]);
        $this->assertDatabaseCount('journal_entries', $journals);
        $this->assertDatabaseCount('customer_receipts', 0);
    }

    public function test_damaged_and_inspection_returns_do_not_increase_available_stock(): void
    {
        $variant = $this->stock();
        $invoice = $this->sale($variant);
        $item = $invoice->items()->first();
        app(ReturnSaleAction::class)->execute($invoice, $this->returnData($invoice, 1, ['rows' => [['sale_item_id' => $item->id, 'quantity' => 1, 'condition' => 'inspection']]]), $this->owner);
        app(ReturnSaleAction::class)->execute($invoice, $this->returnData($invoice, 1, ['rows' => [['sale_item_id' => $item->id, 'quantity' => 1, 'condition' => 'damaged']]]), $this->owner);
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $variant->id, 'quantity' => 8, 'quarantined_quantity' => 1, 'quarantined_value_minor' => 2000, 'damaged_quantity' => 1]);
        $this->assertSame(2000, $this->balance('quarantine'));
        $this->assertSame(2000, $this->balance('inventory_loss'));
        $this->assertSame(0, $this->balance('cogs'));
    }

    public function test_sale_reversal_returns_goods_and_refunds_once_without_deleting_original_documents(): void
    {
        $variant = $this->stock();
        $invoice = $this->sale($variant);
        $data = ['client_uuid' => (string) Str::uuid(), 'reason' => 'عكس تسليم خاطئ', 'goods_confirmed' => true, 'funds_confirmed' => true,
            'refund_payments' => [['account_id' => $this->cash->id, 'amount' => '70']]];
        $reverse = app(ReverseSalesOperationAction::class);
        $reverse->sale($invoice, $data, $this->owner);
        $reverse->sale($invoice, $data, $this->owner);
        $this->assertSame('reversed', $invoice->fresh()->status);
        $this->assertDatabaseCount('sale_invoices', 1);
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $variant->id, 'quantity' => 10, 'value_minor' => 20000]);
        $this->assertSame(0, $this->balance('receivables'));
        $this->assertSame(0, app(PostJournalAction::class)->balance($this->cash->id));
    }

    public function test_exchange_reversal_reverses_both_inventory_legs_and_the_linked_finance_once(): void
    {
        $old = $this->stock('50', '30', 10, 'OLD');
        $new = $this->stock('60', '45', 10, 'NEW');
        $invoice = $this->sale($old, ['rows' => [['product_variant_id' => $old->id, 'quantity' => 1]]]);
        $exchange = app(ExchangeSaleAction::class)->execute($invoice, ['client_uuid' => (string) Str::uuid(), 'reason' => 'استبدال كامل',
            'return_rows' => [['sale_item_id' => $invoice->items()->first()->id, 'quantity' => 1, 'condition' => 'resellable']],
            'rows' => [['product_variant_id' => $new->id, 'quantity' => 1]], 'payments' => [['account_id' => $this->cash->id, 'amount' => '10']]], $this->owner);
        $data = ['reason' => 'عكس الاستبدال', 'goods_confirmed' => true, 'funds_confirmed' => true];
        app(ReverseSalesOperationAction::class)->exchange($exchange, $data, $this->owner);
        app(ReverseSalesOperationAction::class)->exchange($exchange, $data, $this->owner);
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $old->id, 'quantity' => 9, 'value_minor' => 27000]);
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $new->id, 'quantity' => 10, 'value_minor' => 45000]);
        $this->assertSame(0, $invoice->fresh()->returned_minor);
        $this->assertSame(0, $exchange->newInvoice->fresh()->due_minor);
        $this->assertSame(5000, app(PostJournalAction::class)->balance($this->cash->id));
    }

    public function test_return_reversal_is_blocked_if_its_recovered_stock_has_a_later_sale(): void
    {
        $variant = $this->stock();
        $invoice = $this->sale($variant);
        $return = app(ReturnSaleAction::class)->execute($invoice, $this->returnData($invoice, 1), $this->owner);
        $this->sale($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]]]);
        $this->expectException(ValidationException::class);
        app(ReverseSalesOperationAction::class)->saleReturn($return, ['reason' => 'محاولة رجعية', 'goods_confirmed' => true, 'funds_confirmed' => true], $this->owner);
    }

    public function test_sales_block_opening_corrections_and_closing_is_manual_and_audited(): void
    {
        $variant = $this->stock();
        $inventory = Inventory::firstOrFail();
        $this->sale($variant);
        $inventory->refresh()->load('productVariant.product');
        $revision = InventoryState::revision($inventory, $inventory->productVariant, $inventory->productVariant->product);
        $this->deleteJson(route('inventory.destroy', $inventory), ['revision' => $revision])->assertUnprocessable();
        $this->assertNotNull(DB::table('branches')->where('id', $this->branchId)->value('opening_stock_closed_at'));
        $this->postJson(route('inventory.review.close', $this->branchId), ['confirmed' => true, 'reason' => 'اكتمال الإعداد'])->assertUnprocessable();
        $this->assertNotNull(DB::table('branches')->where('id', $this->branchId)->value('opening_stock_closed_at'));
    }

    public function test_closed_periods_block_sale_and_financial_posting(): void
    {
        $variant = $this->stock();
        DB::table('branches')->where('id', $this->branchId)->update(['financial_closed_through' => OperationContext::today()]);
        $this->postJson(route('sales.invoices.complete'), $this->saleData($variant))->assertUnprocessable();
        $this->assertDatabaseCount('sale_invoices', 0);
    }

    public function test_historical_reports_do_not_include_later_collection_or_returns(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));
        $variant = $this->stock();
        $invoice = $this->sale($variant, ['payment_mode' => 'credit', 'payments' => []]);
        $this->travelTo(now()->addDays(2));
        app(RecordCustomerReceiptAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'customer_id' => $this->customer->id,
            'allocations' => [['sale_invoice_id' => $invoice->id, 'amount' => '40']], 'payments' => [['account_id' => $this->cash->id, 'amount' => '40']]], $this->owner);
        $report = app(SalesReports::class)->summary(['from' => '2026-10-20', 'to' => '2026-10-20', 'branch_id' => $this->branchId]);
        $this->assertSame(7000, $report['net_sales_minor']);
        $this->assertSame(4000, $report['cogs_minor']);
        $this->assertSame(7000, $report['receivables_minor']);
        $this->assertCount(0, $report['receipts']);
        $this->travelBack();
    }

    public function test_new_screens_render_and_printing_has_no_posting_effect(): void
    {
        $variant = $this->stock();
        $invoice = $this->sale($variant);
        $counts = [DB::table('journal_entries')->count(), DB::table('stock_movements')->count()];
        foreach (['sales.cashier', 'sales.invoices.index', 'sales.reports', 'customers.index', 'finance.accounts.index', 'inventory.review.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('sales.invoices.show', $invoice))->assertOk();
        $this->get(route('sales.invoices.print', $invoice))->assertOk()->assertDontSee('التكلفة المحفوظة');
        $this->get(route('customers.show', $this->customer))->assertOk();
        $this->get(route('sales.returns.create', $invoice))->assertOk();
        $this->get(route('sales.exchanges.create', $invoice))->assertOk();
        $this->assertSame($counts, [DB::table('journal_entries')->count(), DB::table('stock_movements')->count()]);
    }
}
