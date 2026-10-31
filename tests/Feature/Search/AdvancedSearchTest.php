<?php

namespace Tests\Feature\Search;

use App\Actions\Customers\RecordCustomerReceiptAction;
use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Purchases\ApprovePurchaseInvoiceAction;
use App\Actions\Purchases\RecordSupplierPaymentAction;
use App\Actions\Purchases\SavePurchaseInvoiceAction;
use App\Actions\Sales\ExchangeSaleAction;
use App\Actions\Sales\ReturnSaleAction;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestCase;

class AdvancedSearchTest extends SalesTestCase
{
    public function test_filtered_customer_statement_keeps_complete_period_totals_and_running_balances(): void
    {
        $invoice = $this->sale($this->stock(), ['payment_mode' => 'partial', 'payments' => [['account_id' => $this->cash->id, 'amount' => '30']]]);
        $receipt = app(RecordCustomerReceiptAction::class)->execute(['client_uuid' => (string) Str::uuid(),
            'branch_id' => $this->branchId, 'customer_id' => $this->customer->id,
            'allocations' => [['sale_invoice_id' => $invoice->id, 'amount' => '20']],
            'payments' => [['account_id' => $this->cash->id, 'amount' => '20', 'reference' => 'COLLECT-REF-2026']]], $this->owner);
        $this->get(route('customers.show', [$this->customer, 'entry_q' => $receipt->number]))->assertOk()->assertViewHas('statement',
            fn ($statement) => $statement['debt'] === 2000 && $statement['rows']->count() === 1 && (int) $statement['rows']->first()->debt_balance === 2000);
        $this->get(route('search.index', ['type' => 'receipts', 'q' => 'COLLECT-REF-2026', 'min_amount' => '20', 'max_amount' => '20']))->assertOk()
            ->assertViewHas('results', fn ($results) => $results['groups'][0]['entries']->total() === 1);
        $this->get(route('customers.show', [$this->customer, 'entry_q' => 'لا يوجد']))->assertOk()->assertViewHas('statement',
            fn ($statement) => $statement['debt'] === 2000 && $statement['rows']->total() === 0);
    }

    public function test_supplier_history_filters_documents_and_payments_without_changing_totals(): void
    {
        $variant = $this->stock();
        $supplier = Supplier::factory()->create();
        $draft = app(SavePurchaseInvoiceAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'supplier_id' => $supplier->id,
            'branch_id' => $this->branchId, 'invoice_date' => OperationContext::today(), 'initial_payment' => '0', 'payment_method' => 'cash',
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_cost' => '20']]], $this->owner->id);
        $invoice = app(ApprovePurchaseInvoiceAction::class)->execute($draft, $draft->version, $this->owner->id);
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '100', 'reason' => 'رصيد اختبار مورد'], $this->owner);
        $payment = app(RecordSupplierPaymentAction::class)->execute($invoice, ['account_id' => $this->cash->id, 'client_uuid' => (string) Str::uuid(), 'amount' => '10',
            'method' => 'cash', 'payment_date' => OperationContext::today(), 'reference' => 'SUP-REF-UNIQUE'], $this->owner->id);
        $this->get(route('suppliers.show', [$supplier, 'payments_q' => 'SUP-REF-UNIQUE', 'invoices_q' => $variant->sku]))->assertOk()
            ->assertViewHas('payments', fn ($rows) => $rows->total() === 1)->assertViewHas('entries', fn ($rows) => $rows->total() === 1)
            ->assertViewHas('purchases', '40.00')->assertViewHas('balance', '30.00');
        $response = $this->getJson(route('search.suggestions', ['q' => 'SUP-REF-UNIQUE']))->assertOk();
        $group = collect($response->json('groups'))->firstWhere('type', 'supplier_payments');
        $this->assertCount(1, $group['entries']);
        $this->get($group['entries'][0]['url'])->assertOk()->assertViewHas('payments', fn ($rows) => $rows->first()->id === $payment->id);
        $this->get(route('search.index', ['type' => 'supplier_payments', 'status' => 'approved', 'q' => 'SUP-REF-UNIQUE']))->assertOk();
    }

    public function test_return_and_exchange_results_link_to_original_documents(): void
    {
        $old = $this->stock('35', '20', 10, 'OLD');
        $new = $this->stock('60', '45', 10, 'NEW');
        $invoice = $this->sale($old);
        app(ReturnSaleAction::class)->execute($invoice, $this->returnData($invoice, 1), $this->owner);
        app(ExchangeSaleAction::class)->execute($invoice, ['client_uuid' => (string) Str::uuid(), 'reason' => 'استبدال موثق',
            'return_rows' => [['sale_item_id' => $invoice->items()->first()->id, 'quantity' => 1, 'condition' => 'resellable']],
            'rows' => [['product_variant_id' => $new->id, 'quantity' => 1]], 'payments' => [['account_id' => $this->cash->id, 'amount' => '25']]], $this->owner);
        $response = $this->getJson(route('search.suggestions', ['q' => $old->sku]))->assertOk();
        foreach (['returns', 'exchanges'] as $type) {
            $group = collect($response->json('groups'))->firstWhere('type', $type);
            $this->assertNotNull($group);
            foreach ($group['entries'] as $entry) {
                $this->get($entry['url'])->assertOk();
            }
        }
    }

    public function test_search_query_counts_do_not_grow_with_result_count(): void
    {
        Customer::factory()->create(['name' => 'مجموعة قياس']);
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(route('search.suggestions', ['q' => 'مجموعة']))->assertOk();
            $number = count(array_filter(DB::getQueryLog(), fn ($row) => str_starts_with(strtolower($row['query']), 'select')));
            DB::disableQueryLog();

            return $number;
        };
        $single = $count();
        Customer::factory()->count(30)->create(['name' => 'مجموعة قياس']);
        $this->assertLessThanOrEqual($single + 1, $count());
    }

    public function test_exact_prefix_modes_short_codes_and_literal_patterns(): void
    {
        $variant = $this->stock();
        $this->get(route('search.index', ['type' => 'products', 'q' => 'SHIRT', 'match' => 'prefix']))->assertOk()
            ->assertViewHas('results', fn ($result) => $result['groups'][0]['entries']->total() === 1);
        $this->get(route('search.index', ['type' => 'products', 'q' => 'SHIR', 'match' => 'exact']))->assertOk()
            ->assertViewHas('results', fn ($result) => $result['groups'][0]['entries']->total() === 0);
        $this->getJson(route('search.suggestions', ['q' => 'L']))->assertOk()->assertJsonPath('groups.0.entries.0.type', 'products');
        $this->getJson('/api/products?q='.$variant->sku)->assertOk()->assertJsonCount(1, 'data');
        $category = Category::create(['name' => 'تصنيف بلا أصناف']);
        Product::create(['name' => 'منتج بلا متغيرات', 'code' => 'EMPTY-PRODUCT', 'category_id' => $category->id]);
        $this->getJson('/api/products?q=EMPTY-PRODUCT')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_foreign_branch_records_and_financial_counts_are_scoped_before_search(): void
    {
        $other = DB::table('branches')->insertGetId(['name' => 'بعيد']);
        $supplier = Supplier::factory()->create(['name' => 'مورد مشترك']);
        PurchaseInvoice::factory()->create(['supplier_id' => $supplier->id, 'branch_id' => $other, 'status' => 'approved', 'total_minor' => 5000, 'paid_minor' => 0]);
        $role = SalesAccess::provisionRole('sales_cashier');
        $role->permissions()->attach(Permission::whereIn('name', ['sales.view_cost', 'purchases.view'])->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $this->branchId]);
        $this->actingAs($user);
        $this->get(route('suppliers.show', $supplier))->assertOk()->assertViewHas('entries', fn ($rows) => $rows->total() === 0)->assertViewHas('balance', '0.00');
        $this->get(route('suppliers.index', ['q' => 'مشترك']))->assertOk()->assertViewHas('entries', fn ($rows) => (int) $rows->first()->invoices_count === 0);
        $this->get(route('purchases.invoices.index'))->assertOk()->assertViewHas('entries', fn ($rows) => $rows->total() === 0);
    }

    public function test_global_routes_require_authentication_and_reject_invalid_filters(): void
    {
        $this->getJson(route('search.suggestions', ['q' => 'shirt', 'branch_id' => ['bad']]))->assertUnprocessable();
        $this->getJson(route('search.suggestions', ['q' => 'shirt', 'sort' => 'id desc; DROP TABLE']))->assertUnprocessable();
        $this->getJson(route('search.suggestions', ['q' => 'shirt', 'type' => 'users']))->assertUnprocessable();
        $this->postJson(route('search.suggestions'), ['q' => 'shirt'])->assertStatus(405);
        Auth::logout();
        $this->getJson(route('search.suggestions', ['q' => 'shirt']))->assertUnauthorized();
    }
}
