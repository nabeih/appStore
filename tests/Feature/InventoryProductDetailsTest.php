<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\User;
use App\Support\Sales\SalesAccess;
use Database\Factories\BranchFactory;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Feature\Sales\SalesTestCase;

class InventoryProductDetailsTest extends SalesTestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') === 'mysql' && ! in_array(getenv('DB_DATABASE'), ['appstore_fabric_swatch_test', 'appstore_golive_20261031_tests'], true)) {
            throw new \RuntimeException('Only the approved isolated MySQL database may be used.');
        }
        parent::setUp();
    }

    private function viewer(bool $manage = false): User
    {
        $role = SalesAccess::provisionRole('inventory_viewer');
        if ($manage) {
            $role->permissions()->syncWithoutDetaching(Permission::where('name', 'inventory.manage_catalog')->pluck('id'));
        }

        return User::factory()->create(['role_id' => $role->id, 'branch_id' => $this->branchId]);
    }

    private function extraVariant(ProductVariant $first, string $colorName = 'أحمر', string $sizeName = 'M'): ProductVariant
    {
        $color = Color::firstOrCreate(['name' => $colorName], ['code' => 'RED']);
        $size = Size::firstOrCreate(['name' => $sizeName], ['code' => $sizeName]);

        return ProductVariant::create(['product_id' => $first->product_id, 'color_id' => $color->id, 'size_id' => $size->id,
            'sku' => $first->product->code.'-'.$color->code.'-'.$size->code, 'selling_price' => '49.50']);
    }

    public function test_product_has_a_real_show_link_around_name_and_image_without_wrapping_row_actions(): void
    {
        $variant = $this->stock();
        ProductImage::create(['product_id' => $variant->product_id, 'color_id' => $variant->color_id,
            'image_path' => 'products/fixture.webp', 'thumbnail_path' => 'products/fixture-thumb.webp']);
        $response = $this->get(route('inventory.index', ['q' => $variant->sku, 'page' => 1]))->assertOk();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $xpath = new DOMXPath($document);
        $this->assertSame(1, $xpath->query('//a[contains(@class,"inventory-product-link")]/img')->length);
        $this->assertSame(0, $xpath->query('//a[contains(@class,"inventory-product-link")]//button')->length);
        $this->assertGreaterThan(0, $xpath->query('//td[contains(@class,"inventory-setup-actions")]//button')->length);
        $this->assertStringContainsString('/inventory/products/'.$variant->product_id, $xpath->query('//a[contains(@class,"inventory-product-link")]')->item(0)->getAttribute('href'));
    }

    public function test_multiple_colors_sizes_prices_and_scoped_stocks_use_existing_color_images(): void
    {
        $first = $this->stock();
        $first->product->update(['description' => 'وصف متعدد الألوان']);
        $red = $this->extraVariant($first);
        $large = $this->extraVariant($first, 'أحمر', 'XL');
        foreach ([$red, $large] as $variant) {
            Inventory::create(['branch_id' => $this->branchId, 'product_variant_id' => $variant->id,
                'quantity' => 3, 'average_cost' => 17, 'value_minor' => 5100]);
        }
        ProductImage::create(['product_id' => $first->product_id, 'color_id' => $red->color_id,
            'image_path' => 'products/red.webp', 'thumbnail_path' => 'products/red-thumb.webp']);
        $response = $this->get(route('inventory.products.show', $first->product_id))->assertOk()
            ->assertSee('وصف متعدد الألوان')->assertSee($first->sku)->assertSee($red->sku)->assertSee($large->sku)
            ->assertSee('49.50')->assertSee('متوسط التكلفة')->assertSee('تعديل المنتج')->assertSee('لا توجد صورة لهذا اللون');
        $this->assertSame(1, substr_count($response->getContent(), 'src="'.asset('storage/products/red-thumb.webp').'"'));
        if (getenv('PRODUCT_DETAILS_PREVIEW') === '1') {
            File::ensureDirectoryExists(storage_path('app/reports/product-details-preview'));
            file_put_contents(storage_path('app/reports/product-details-preview/show.html'), $response->getContent());
            file_put_contents(storage_path('app/reports/product-details-preview/list.html'), $this->get(route('inventory.index'))->assertOk()->getContent());
        }
    }

    public function test_viewer_cannot_access_foreign_products_or_see_foreign_variants_branches_or_cost(): void
    {
        $first = $this->stock(cost: '123.45');
        $first->update(['minimum_selling_price' => '998.87']);
        $foreignBranch = BranchFactory::new()->create(['name' => 'فرع سري']);
        $hidden = $this->extraVariant($first);
        Inventory::create(['branch_id' => $foreignBranch->id, 'product_variant_id' => $hidden->id,
            'quantity' => 9876, 'average_cost' => 7654, 'value_minor' => 7559090400]);
        ProductImage::create(['product_id' => $first->product_id, 'color_id' => $hidden->color_id, 'image_path' => 'products/foreign-secret.webp']);
        $foreign = $this->stock(code: 'FOREIGN');
        Inventory::where('product_variant_id', $foreign->id)->update(['branch_id' => $foreignBranch->id]);
        $this->actingAs($this->viewer());
        $response = $this->get(route('inventory.products.show', $first->product_id))->assertOk()->assertSee($first->sku)
            ->assertDontSee('فرع سري')->assertDontSee($hidden->sku)->assertDontSee('9876')->assertDontSee('123.45')
            ->assertDontSee('998.87')->assertDontSee('متوسط التكلفة')->assertDontSee('foreign-secret.webp')
            ->assertDontSee('data-product-details', false)->assertDontSee('data-product-delete', false);
        $visible = $response->viewData('product')->variants->first();
        $this->assertArrayNotHasKey('minimum_selling_price', $visible->getAttributes());
        $this->assertArrayNotHasKey('average_cost', $visible->inventories->first()->getAttributes());
        $this->assertArrayNotHasKey('value_minor', $visible->inventories->first()->getAttributes());
        $this->putJson('/api/products/'.$first->product_id, ['name' => 'غير مسموح'])->assertForbidden();
        $this->deleteJson('/api/products/'.$first->product_id)->assertForbidden();
        $this->get(route('inventory.products.show', $foreign->product_id))->assertNotFound();
    }

    public function test_permissions_are_enforced_on_the_server_and_mutations_cannot_target_foreign_products(): void
    {
        $variant = $this->stock();
        $this->actingAs(User::factory()->create());
        $this->get(route('inventory.products.show', $variant->product_id))->assertForbidden();
        $foreignBranch = BranchFactory::new()->create();
        Inventory::where('product_variant_id', $variant->id)->update(['branch_id' => $foreignBranch->id]);
        $this->actingAs($this->viewer(true));
        $this->putJson('/api/products/'.$variant->product_id, ['name' => 'تعديل مرفوض'])->assertNotFound();
        $this->deleteJson('/api/products/'.$variant->product_id)->assertNotFound();
        $this->assertTrue($variant->product->fresh()->is_active);
    }

    public function test_empty_product_and_unstocked_variant_have_explicit_empty_states_for_catalog_manager(): void
    {
        $category = Category::firstOrCreate(['name' => 'فارغ']);
        $product = Product::create(['name' => 'منتج جديد', 'code' => 'EMPTY', 'category_id' => $category->id]);
        $this->get(route('inventory.products.show', $product->id))->assertOk()->assertSee('لا يوجد وصف')
            ->assertSee('لا توجد تركيبات')->assertSee('تعديل المنتج');
        $first = $this->stock();
        $unstocked = $this->extraVariant($first);
        $this->get(route('inventory.products.show', $first->product_id))->assertOk()
            ->assertSee($unstocked->sku)->assertSee('لا يوجد رصيد مخزون مسجل');
        $this->actingAs($this->viewer());
        $this->get(route('inventory.products.show', $product->id))->assertNotFound();
    }

    public function test_return_filters_are_preserved_without_accepting_an_external_return_url(): void
    {
        $variant = $this->stock();
        $filters = ['q' => 'قميص', 'page' => 3, 'branch_id' => $this->branchId, 'color_id' => $variant->color_id, 'sort' => 'oldest'];
        $response = $this->get(route('inventory.products.show', ['product' => $variant->product_id,
            'list' => $filters + ['return_url' => 'https://example.com/steal']]))->assertOk();
        $response->assertSee(route('inventory.index', $filters))->assertDontSee('example.com/steal');
    }

    public function test_show_is_read_only_and_query_count_does_not_grow_per_variant(): void
    {
        $variant = $this->stock();
        $before = DB::table('inventories')->orderBy('id')->get()->toJson();
        $count = function () use ($variant): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('inventory.products.show', $variant->product_id))->assertOk();
            $queries = count(array_filter(DB::getQueryLog(), fn (array $entry): bool => str_starts_with(strtolower($entry['query']), 'select')));
            DB::disableQueryLog();

            return $queries;
        };
        $single = $count();
        for ($index = 0; $index < 12; $index++) {
            $this->extraVariant($variant, 'أحمر', 'SIZE'.$index);
        }
        $multiple = $count();
        $this->assertLessThanOrEqual($single + 1, $multiple);
        if (getenv('PRODUCT_DETAILS_PREVIEW') === '1') {
            File::ensureDirectoryExists(storage_path('app/reports/product-details-preview'));
            file_put_contents(storage_path('app/reports/product-details-preview/queries.json'), json_encode(['one_variant' => $single, 'thirteen_variants' => $multiple], JSON_PRETTY_PRINT));
        }
        $this->assertSame($before, DB::table('inventories')->orderBy('id')->get()->toJson());
        $this->assertSame('SHIRT-BLK-L', $variant->fresh()->sku);
    }

    public function test_existing_product_delete_policy_disables_documented_products_without_deleting_stock(): void
    {
        $variant = $this->stock();
        $before = DB::table('inventories')->get()->toJson();
        $this->deleteJson('/api/products/'.$variant->product_id)->assertOk();
        $this->assertFalse($variant->product->fresh()->is_active);
        $this->assertSame($before, DB::table('inventories')->get()->toJson());
        $this->get(route('inventory.products.show', $variant->product_id))->assertOk()->assertSee('منتج معطّل');
    }

    public function test_archived_unreferenced_product_is_read_only_and_metadata_edit_preserves_sku(): void
    {
        $variant = $this->stock();
        $sku = $variant->sku;
        $this->putJson('/api/products/'.$variant->product_id, ['name' => 'اسم جديد', 'description' => 'وصف جديد'])->assertOk();
        $this->assertSame($sku, $variant->fresh()->sku);
        $empty = Product::create(['name' => 'مؤرشف', 'code' => 'ARCHIVE', 'category_id' => $variant->product->category_id]);
        $this->deleteJson('/api/products/'.$empty->id)->assertOk();
        $this->assertTrue(Product::withTrashed()->findOrFail($empty->id)->trashed());
        $this->get(route('inventory.products.show', $empty->id))->assertOk()
            ->assertDontSee('data-product-delete', false)->assertDontSee('data-product-details', false);
    }
}
