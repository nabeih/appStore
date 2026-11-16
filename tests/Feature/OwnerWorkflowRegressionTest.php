<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Size;
use App\Models\User;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestCase;

class OwnerWorkflowRegressionTest extends SalesTestCase
{
    private function limited(array $permissions): User
    {
        $role = Role::create(['name' => 'workflow_'.Str::uuid(), 'display_name' => 'صلاحيات اختبار معزولة', 'is_active' => true]);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create(['role_id' => $role->id, 'branch_id' => $this->branchId]);
    }

    public function test_inventory_hides_opening_and_purchase_controls_when_cost_permission_is_absent(): void
    {
        $actor = $this->limited(['inventory.view', 'inventory.correct_setup', 'purchases.view', 'purchases.manage']);
        $this->actingAs($actor)->get(route('inventory.index'))->assertOk()->assertDontSee('id="quickOpeningStockModal"', false)
            ->assertDontSee('href="'.route('purchases.invoices.create').'"', false)->assertDontSee('href="'.route('purchases.invoices.index').'"', false);
    }

    public function test_accounts_do_not_link_cashier_for_user_without_sale_permissions(): void
    {
        $this->actingAs($this->limited(['sales.manage_accounts']))->get(route('finance.accounts.index'))->assertOk()
            ->assertDontSee('href="'.route('sales.cashier').'"', false);
    }

    public function test_operation_does_not_offer_readiness_link_when_its_permission_is_absent(): void
    {
        $this->owner->role->permissions()->detach(Permission::where('name', 'inventory.valuation_review')->value('id'));
        $this->actingAs($this->owner->fresh())->get(route('inventory.operation.index', ['branch_id' => $this->branchId]))->assertOk()
            ->assertDontSee('href="'.route('inventory.review.index', ['branch_id' => $this->branchId]).'"', false);
    }

    public function test_minimum_price_management_requires_explicit_global_pricing_authority_without_exposing_cost(): void
    {
        $variant = $this->stock();
        $this->actingAs($this->limited(['inventory.manage_catalog', 'inventory.view', 'catalog.search']))
            ->get(route('catalog.minimum.edit', $variant->product_id))->assertForbidden();
        $actor = $this->limited(['inventory.manage_catalog', 'inventory.view', 'catalog.search', 'sales.override_price', 'sales.all_branches']);
        $this->actingAs($actor)->get(route('catalog.minimum.edit', $variant->product_id))->assertOk();
        $this->put(route('catalog.minimum.update', $variant->product_id), ['variants' => [['id' => $variant->id, 'minimum' => '32']]])->assertRedirect();
        $this->assertSame('32.00', $variant->fresh()->minimum_selling_price);
        $this->getJson('/api/products/'.$variant->product_id)->assertOk()->assertDontSee('average_cost')->assertDontSee('unit_cost');
    }

    public function test_purchase_product_options_do_not_require_opening_stock_correction_permission(): void
    {
        $actor = $this->limited(['purchases.view', 'purchases.manage', 'sales.view_cost', 'inventory.manage_catalog']);
        $this->actingAs($actor)->getJson(route('inventory.opening-stock.options'))->assertForbidden();
        $this->getJson(route('purchases.products.options'))->assertOk()->assertJsonStructure(['categories', 'colors', 'sizes']);
        $page = $this->get(route('purchases.invoices.create'))->assertOk();
        preg_match('#<script[^>]*id="purchase-config"[^>]*>(.*?)</script>#s', $page->getContent(), $match);
        $this->assertSame(route('purchases.products.options'), json_decode($match[1], true, 512, JSON_THROW_ON_ERROR)['optionsUrl']);
    }

    public function test_purchase_name_search_tolerates_existing_spacing_and_does_not_require_stock(): void
    {
        $variant = $this->stock();
        $variant->product->update(['name' => 'بلوزة  نص   كم']);
        $this->getJson(route('purchases.products.index', ['q' => 'بلوزة نص كم', 'branch_id' => $this->branchId]))
            ->assertOk()->assertJsonPath('data.0.product_variant_id', $variant->id);
    }

    public function test_product_without_variants_can_be_selected_without_duplicate_product_or_stock(): void
    {
        $category = Category::create(['name' => 'عرض مستقل']);
        $product = Product::create(['name' => 'منتج جديد بلا مقاسات', 'code' => 'EMPTY-DEMO', 'category_id' => $category->id, 'is_active' => true]);
        $this->getJson(route('purchases.products.index', ['q' => $product->name, 'branch_id' => $this->branchId]))
            ->assertOk()->assertJsonPath('catalog_products.0.product_id', $product->id);
        $color = Color::create(['name' => 'لون مستقل', 'code' => 'NEW']);
        $size = Size::create(['name' => 'M', 'code' => 'M']);
        $data = ['product_id' => $product->id, 'colors' => [$color->id], 'sizes' => [$size->id], 'selling_price' => '50', 'minimum_selling_price' => '40'];
        $this->postJson(route('purchases.products.store'), $data)->assertCreated();
        $this->postJson(route('purchases.products.store'), $data)->assertCreated();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseCount('inventories', 0);
        $this->assertSame('40.00', ProductVariant::firstOrFail()->minimum_selling_price);
    }

    public function test_opening_sets_minimum_without_stock_on_draft_and_rejects_conflicting_replay(): void
    {
        $category = Category::create(['name' => 'تصنيف مستقل']);
        $color = Color::create(['name' => 'أزرق مستقل', 'code' => 'B']);
        $size = Size::create(['name' => 'XS', 'code' => 'XS']);
        $data = ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'opening_date' => OperationContext::today(),
            'rows' => [['name' => 'تسعير افتتاح مستقل', 'category_id' => $category->id, 'color_id' => $color->id, 'size_id' => $size->id,
                'qty' => 2, 'cost' => '20', 'price' => '35', 'minimum_selling_price' => '30']]];
        $this->postJson(route('inventory.opening-stock.drafts.store'), $data)->assertOk();
        $this->assertDatabaseCount('inventories', 0);
        $this->assertSame('30.00', ProductVariant::firstOrFail()->minimum_selling_price);
        $data['rows'][0]['minimum_selling_price'] = '31';
        $this->postJson(route('inventory.opening-stock.drafts.store'), $data)->assertConflict();
    }

    public function test_lowest_price_equal_to_threshold_succeeds_and_cashier_confirmation_cannot_bypass_lower_total(): void
    {
        $variant = $this->stock();
        $variant->update(['minimum_selling_price' => '34']);
        $cashier = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $this->actingAs($cashier);
        $data = $this->saleData($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]], 'discount' => ['type' => 'amount', 'value' => '1']]);
        $this->postJson(route('sales.invoices.complete'), $data)->assertOk();
        $data['client_uuid'] = (string) Str::uuid();
        $data['discount']['value'] = '1.01';
        $data['below_minimum_confirmed'] = true;
        $this->postJson(route('sales.invoices.complete'), $data)->assertUnprocessable()->assertJsonValidationErrors('below_minimum_confirmed');
        $this->assertDatabaseCount('sale_invoices', 1);
    }

    public function test_purchase_search_cannot_read_cost_or_product_from_another_branch_by_changing_parameters(): void
    {
        $variant = $this->stock();
        $other = Branch::factory()->create();
        Inventory::where('product_variant_id', $variant->id)->update(['branch_id' => $other->id]);
        $this->owner->forceFill(['branch_id' => $this->branchId])->save();
        $this->owner->role->permissions()->detach(Permission::where('name', 'sales.all_branches')->value('id'));
        $this->actingAs($this->owner->fresh());
        $this->getJson(route('purchases.products.index', ['q' => 'SHIRT', 'branch_id' => $other->id]))->assertForbidden();
        $this->getJson(route('purchases.products.index', ['q' => 'SHIRT', 'branch_id' => $this->branchId]))->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(route('purchases.products.store'), ['product_id' => $variant->product_id, 'colors' => [$variant->color_id],
            'sizes' => [$variant->size_id], 'selling_price' => '50'])->assertUnprocessable()->assertJsonValidationErrors('product_id');
    }
}
