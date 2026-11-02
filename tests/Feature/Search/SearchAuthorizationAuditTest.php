<?php

namespace Tests\Feature\Search;

use App\Models\Color;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\ProductColorSwatch;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SaleInvoice;
use App\Models\Size;
use App\Models\User;
use App\Support\Sales\SalesAccess;
use Database\Factories\BranchFactory;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sales\SalesTestCase;

class SearchAuthorizationAuditTest extends SalesTestCase
{
    private User $cashier;

    private ProductVariant $local;

    private ProductVariant $foreign;

    private int $foreignBranch;

    private SaleInvoice $foreignInvoice;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'appstore_golive_20261031_tests') {
            throw new \LogicException('Isolated MySQL audit only.');
        }
        $this->local = $this->stock('73.19', '28.47', 7, 'AUDIT-LOCAL');
        $this->foreign = $this->stock('89.13', '37.29', 13, 'AUDIT-FOREIGN');
        $this->foreignBranch = BranchFactory::new()->create(['name' => 'AUDIT-SECRET-BRANCH'])->id;
        Inventory::where('product_variant_id', $this->foreign->id)->update(['branch_id' => $this->foreignBranch]);
        $customer = Customer::factory()->create(['name' => 'AUDIT-FOREIGN-CUSTOMER', 'phone' => '0599123987']);
        $this->foreignInvoice = SaleInvoice::factory()->create(['branch_id' => $this->foreignBranch, 'customer_id' => $customer->id, 'customer_name' => $customer->name, 'cashier_name' => 'AUDIT-FOREIGN-EMPLOYEE']);
        $this->cashier = User::factory()->create(['role_id' => SalesAccess::provisionRole('sales_cashier')->id, 'branch_id' => $this->branchId]);
        $this->actingAs($this->cashier);
    }

    public function test_cashier_direct_search_apis_hide_cost_foreign_stock_and_pagination_counts(): void
    {
        foreach (['/search/products', '/api/inventories', '/api/products'] as $path) {
            $response = $this->getJson($path)->assertOk()->assertJsonPath('total', 1);
            $body = $response->getContent();
            foreach (['average_cost', 'value_minor', 'minimum_selling_price', 'cost_minor', 'gross_profit', 'AUDIT-FOREIGN'] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
            $this->getJson($path.'?q=AUDIT-FOREIGN&branch_id='.$this->foreignBranch)->assertOk()->assertJsonPath('total', 0);
        }
        $this->getJson(route('sales.catalog', ['branch_id' => $this->branchId]))->assertOk()->assertJsonMissingPath('data.0.variants.0.unit_cost');
        $this->getJson(route('sales.catalog', ['branch_id' => $this->foreignBranch]))->assertForbidden();
        $this->postJson(route('search.products.codes'), ['codes' => [$this->local->sku, $this->foreign->sku], 'branch_id' => $this->branchId])->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/products/'.$this->foreign->product_id)->assertNotFound();
        $this->get(route('inventory.products.show', $this->foreign->product_id))->assertNotFound();
        $this->get(route('search.products.labels', ['variants' => [$this->foreign->id]]))->assertNotFound()->assertDontSee('AUDIT-FOREIGN');
        $this->get(route('search.products.labels', ['variants' => [$this->local->id]]))->assertOk()->assertDontSee('28.47');
        $html = $this->get(route('inventory.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('28.47', $html);
        $this->assertStringNotContainsString('AUDIT-FOREIGN', $html);
    }

    public function test_cashier_cannot_expand_document_scope_or_follow_foreign_document_links(): void
    {
        foreach ([$this->foreignInvoice->number, 'AUDIT-FOREIGN-EMPLOYEE', 'AUDIT-FOREIGN-CUSTOMER'] as $term) {
            $this->getJson(route('search.suggestions', ['type' => 'sales', 'q' => $term, 'branch_id' => $this->foreignBranch]))->assertOk()->assertJsonCount(0, 'groups');
            $this->get(route('search.index', ['type' => 'sales', 'q' => $term]))->assertOk()
                ->assertViewHas('results', fn ($results) => $results['groups'][0]['entries']->total() === 0);
            $this->get(route('sales.invoices.index', ['q' => $term, 'branch_id' => $this->foreignBranch]))->assertOk()
                ->assertViewHas('entries', fn ($entries) => $entries->total() === 0);
        }
        foreach (['sales.invoices.show', 'sales.invoices.print', 'sales.invoices.edit'] as $route) {
            $this->get(route($route, $this->foreignInvoice))->assertForbidden()->assertDontSee('AUDIT-FOREIGN');
        }
        $this->actingAs($this->owner)->getJson(route('search.suggestions', ['type' => 'sales', 'q' => $this->foreignInvoice->number]))
            ->assertOk()->assertJsonPath('groups.0.entries.0.id', $this->foreignInvoice->id);
    }

    public function test_admin_finance_purchases_and_exports_are_denied_even_with_query_parameter_injection(): void
    {
        foreach (['suppliers', 'purchases', 'openings', 'supplier_payments', 'accounts', 'categories', 'colors', 'sizes'] as $type) {
            $this->getJson(route('search.suggestions', ['type' => $type, 'q' => 'AUDIT']))->assertForbidden();
            $this->get(route('search.index', ['type' => $type, 'q' => 'AUDIT']))->assertForbidden();
        }
        foreach (['/access/users', '/access/roles', '/branches', '/suppliers', '/purchases', '/purchases/products', '/purchases/suppliers',
            '/inventory/opening-stock/products', '/inventory/opening-stock/options', '/inventory/readiness', '/inventory/readiness/export',
            '/finance/treasury', '/finance/accounts', '/finance/expenses', '/finance/transition', '/sales/reports', '/search/visual-index'] as $path) {
            $this->getJson($path.'?q=AUDIT&branch_id='.$this->foreignBranch.'&export=1&include_cost=1')->assertForbidden();
        }
        $this->getJson(route('search.suggestions', ['type' => 'employees', 'q' => 'AUDIT']))->assertUnprocessable();
        session()->forget('password_hash_web');
        $this->actingAs(User::factory()->create(['branch_id' => $this->branchId]));
        $this->getJson(route('search.suggestions', ['q' => 'AUDIT']))->assertOk()->assertJsonCount(0, 'groups');
        foreach (['/search/products', '/api/products', '/api/inventories', '/customers'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
    }

    public function test_valuation_filters_are_rejected_outside_authorized_inventory_review_even_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        foreach (['missing', 'reviewed', 'mismatch'] as $valuation) {
            foreach (['/search/suggestions', '/search', '/api/products', '/api/inventories'] as $path) {
                $response = $this->getJson($path.'?type=products&q=AUDIT&valuation='.$valuation)
                    ->assertUnprocessable()->assertJsonValidationErrors('valuation');
                foreach (['exception', 'trace', 'SQLSTATE', 'history.history_quantity'] as $secret) {
                    $this->assertStringNotContainsString($secret, $response->getContent());
                }
            }
        }
        $this->actingAs($this->owner)->get(route('inventory.review.index', ['valuation' => 'mismatch']))->assertOk();
        $this->get(route('inventory.review.export', ['valuation' => 'mismatch']))->assertOk();
    }

    public function test_shared_customer_directory_is_global_but_financial_statement_rejects_foreign_branch(): void
    {
        $this->getJson(route('customers.index', ['q' => 'AUDIT-FOREIGN-CUSTOMER', 'branch_id' => $this->foreignBranch]))
            ->assertOk()->assertJsonPath('data.0.name', 'AUDIT-FOREIGN-CUSTOMER');
        $this->getJson(route('search.suggestions', ['type' => 'customers', 'q' => 'AUDIT-FOREIGN-CUSTOMER']))
            ->assertOk()->assertJsonPath('groups.0.entries.0.title', 'AUDIT-FOREIGN-CUSTOMER');
        $this->get(route('customers.show', ['customer' => $this->foreignInvoice->customer_id, 'branch_id' => $this->foreignBranch]))->assertForbidden();
    }

    public function test_shared_products_never_expose_foreign_only_variants_through_search_or_direct_links(): void
    {
        $size = Size::create(['name' => 'AUDIT-FOREIGN-SIZE', 'code' => 'AFS']);
        $color = Color::create(['name' => 'AUDIT-FOREIGN-COLOR', 'code' => 'AFC']);
        $foreignVariant = ProductVariant::create(['product_id' => $this->local->product_id, 'color_id' => $color->id, 'size_id' => $size->id,
            'sku' => 'AUDIT-SECRET-VARIANT', 'selling_price' => '91.23', 'minimum_selling_price' => '43.21', 'is_active' => true]);
        Inventory::create(['branch_id' => $this->foreignBranch, 'product_variant_id' => $foreignVariant->id, 'quantity' => 431, 'average_cost' => '43.21', 'value_minor' => 1862451]);
        ProductImage::create(['product_id' => $this->local->product_id, 'color_id' => $color->id, 'image_path' => 'audit-foreign-image.webp']);
        ProductColorSwatch::create(['product_id' => $this->local->product_id, 'color_id' => $color->id, 'image_path' => 'audit-foreign-swatch.webp']);
        foreach (['/api/products?q=AUDIT-SECRET-VARIANT', '/api/products/'.$this->local->product_id,
            route('sales.catalog', ['branch_id' => $this->branchId, 'q' => 'AUDIT-SECRET-VARIANT'])] as $path) {
            $response = $this->getJson($path)->assertOk();
            $records = json_encode($response->json('data') ?? $response->json(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('AUDIT-SECRET-VARIANT', $records);
            $this->assertStringNotContainsString('AUDIT-FOREIGN-SIZE', $response->getContent());
            $this->assertStringNotContainsString('43.21', $response->getContent());
        }
        $this->getJson('/api/products?q=AUDIT-SECRET-VARIANT')->assertJsonPath('total', 0);
        $this->getJson('/api/products?size_id='.$size->id)->assertJsonPath('total', 0);
        $this->getJson('/api/products?branch_id='.$this->foreignBranch)->assertJsonPath('total', 0);
        $this->getJson('/api/products?q='.$this->local->sku)->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.variants.0.sku', $this->local->sku);
        $this->getJson('/api/products/'.$this->local->product_id)->assertJsonCount(1, 'variants');
        foreach (['/api/products/'.$this->local->product_id, route('sales.catalog', ['branch_id' => $this->branchId])] as $path) {
            $response = $this->getJson($path)->assertOk();
            foreach (['audit-foreign-image', 'audit-foreign-swatch', 'AUDIT-FOREIGN-COLOR'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
        $this->getJson(route('sales.catalog', ['branch_id' => $this->branchId, 'q' => 'AUDIT-SECRET-VARIANT']))->assertJsonCount(0, 'data');
        $this->get(route('inventory.products.show', $this->local->product_id))->assertOk()->assertDontSee('AUDIT-SECRET-VARIANT');
        $this->actingAs($this->owner)->getJson('/api/products?q=AUDIT-SECRET-VARIANT')->assertOk()
            ->assertJsonPath('data.0.variants.0.sku', 'AUDIT-SECRET-VARIANT');
        $this->getJson(route('sales.catalog', ['branch_id' => $this->branchId, 'q' => 'AUDIT-SECRET-VARIANT']))->assertJsonCount(0, 'data');
        $this->getJson(route('sales.catalog', ['branch_id' => $this->foreignBranch, 'q' => 'AUDIT-SECRET-VARIANT']))
            ->assertOk()->assertJsonPath('data.0.variants.0.sku', 'AUDIT-SECRET-VARIANT');
    }
}
