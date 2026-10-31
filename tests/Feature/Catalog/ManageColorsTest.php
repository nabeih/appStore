<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Color;
use App\Models\OpeningStockItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Size;
use App\Models\User;
use App\Support\Catalog\ColorPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManageColorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->salesOwner()->create());
    }

    private function product(): Product
    {
        $category = Category::create(['name' => 'ملابس']);

        return Product::create(['name' => 'قميص', 'code' => 'SHIRT', 'category_id' => $category->id]);
    }

    public function test_the_expanded_palette_has_searchable_families_and_consistent_arabic_and_english_suggestions(): void
    {
        $palette = ColorPalette::presets();
        $this->assertCount(96, $palette);
        $this->assertCount(8, array_unique(array_column($palette, 'family')));
        $names = [];
        foreach ($palette as $preset) {
            $this->assertMatchesRegularExpression('/\A#[0-9A-F]{6}\z/', $preset['hex']);
            $this->assertNotContains($preset['name'], $names);
            $names[] = $preset['name'];
            foreach ([$preset['name'], ...$preset['aliases']] as $name) {
                $this->assertSame($preset['hex'], ColorPalette::suggest($name), $name);
            }
        }
        $this->assertSame('#A3B18A', ColorPalette::suggest('أَخْضَر مريمي'));
        $this->assertSame('#C99CA4', ColorPalette::suggest('dusty-rose'));
        $this->assertSame('#8B6F5A', ColorPalette::suggest('موكا'));
        $this->get(route('catalog.attributes.index', ['tab' => 'colors']))->assertOk()
            ->assertSee('96 درجة')->assertSee('data-palette-search', false)->assertSee('data-palette-family', false);
        $this->assertDatabaseCount('colors', 0);
    }

    public function test_a_new_palette_shade_can_be_added_by_name_without_a_custom_hex(): void
    {
        $this->postJson(route('catalog.attributes.colors.store'), ['name' => 'أخضر مريمي'])->assertCreated();
        $this->assertDatabaseHas('colors', ['name' => 'أخضر مريمي', 'hex_code' => '#A3B18A']);
    }

    public function test_renaming_a_used_color_preserves_stock_skus_prices_images_and_document_snapshots(): void
    {
        $color = Color::create(['name' => 'أزرق', 'code' => 'BLU']);
        $product = $this->product();
        $size = Size::create(['name' => 'L', 'code' => 'L']);
        $branchId = DB::table('branches')->insertGetId(['name' => 'الرئيسي']);
        $this->postJson(route('inventory.opening-stock.store'), [
            'client_uuid' => (string) Str::uuid(), 'branch_id' => $branchId, 'opening_date' => now()->toDateString(),
            'rows' => [['name' => $product->name, 'code' => $product->code, 'category_id' => $product->category_id, 'color_id' => $color->id,
                'size_id' => $size->id, 'qty' => 3, 'cost' => '10.00', 'price' => '25.00']],
        ])->assertCreated();
        $item = OpeningStockItem::firstOrFail();
        $variant = $item->productVariant;
        $image = ProductImage::create(['product_id' => $product->id, 'color_id' => $color->id, 'image_path' => 'products/colors/kept.png']);
        $this->putJson(route('catalog.attributes.colors.update', $color), ['name' => '  أزرق دنيم  ', 'hex_code' => '#536b8e'])->assertOk();
        $this->assertDatabaseHas('colors', ['id' => $color->id, 'name' => 'أزرق دنيم', 'code' => 'BLU', 'hex_code' => '#536B8E']);
        $this->assertSame('SHIRT-BLU-L', $variant->fresh()->sku);
        $this->assertSame('25.00', $variant->fresh()->selling_price);
        $this->assertSame('أزرق', $item->fresh()->color_name);
        $this->assertSame('products/colors/kept.png', $image->fresh()->image_path);
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $variant->id, 'quantity' => 3, 'average_cost' => '10.00']);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_renaming_alone_preserves_the_existing_degree_including_legacy_name_suggestions(): void
    {
        $color = Color::create(['name' => 'أزرق', 'code' => 'BLU']);
        $this->putJson(route('catalog.attributes.colors.update', $color), ['name' => 'لون خاص'])->assertOk();
        $this->assertSame('#2563EB', $color->fresh()->hex_code);
        $this->putJson(route('catalog.attributes.colors.update', $color), ['name' => 'لون مخصص', 'hex_code' => null])->assertOk();
        $this->assertSame('#2563EB', $color->fresh()->hex_code);
        $unknown = Color::create(['name' => 'درجة قديمة', 'code' => 'OLD']);
        $this->putJson(route('catalog.attributes.colors.update', $unknown), ['name' => 'اسم مصحح'])->assertOk();
        $this->assertNull($unknown->fresh()->hex_code);
    }

    public function test_duplicate_blank_long_names_invalid_hex_and_business_code_changes_are_rejected(): void
    {
        $color = Color::create(['name' => 'أسود', 'code' => 'BLK', 'hex_code' => '#000000']);
        Color::create(['name' => 'أبيض', 'code' => 'WHT']);
        foreach ([['name' => 'أبيض'], ['name' => '   '], ['name' => str_repeat('س', 101)], ['name' => ['bad']],
            ['name' => 'أسود', 'hex_code' => '#123456;background:red'], ['name' => 'أسود', 'code' => 'NEW'],
        ] as $data) {
            $this->putJson(route('catalog.attributes.colors.update', $color), $data)->assertUnprocessable();
        }
        $this->putJson(route('catalog.attributes.colors.update', $color), ['name' => 'أسود', 'hex_code' => '#000000'])->assertOk();
        $this->assertDatabaseHas('colors', ['id' => $color->id, 'name' => 'أسود', 'code' => 'BLK', 'hex_code' => '#000000']);
    }

    public function test_unused_colors_can_be_deleted_without_affecting_other_colors(): void
    {
        $color = Color::create(['name' => 'خاص', 'code' => 'CUSTOM']);
        $kept = Color::create(['name' => 'أسود', 'code' => 'BLK']);
        $this->deleteJson(route('catalog.attributes.colors.destroy', $color))->assertOk()->assertJsonPath('tab', 'colors');
        $this->assertDatabaseMissing('colors', ['id' => $color->id]);
        $this->assertDatabaseHas('colors', ['id' => $kept->id]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_even_inactive_variants_prevent_color_deletion(): void
    {
        $color = Color::create(['name' => 'أسود', 'code' => 'BLK']);
        $product = $this->product();
        $size = Size::create(['name' => 'L', 'code' => 'L']);
        $variant = $product->variants()->create(['color_id' => $color->id, 'size_id' => $size->id, 'sku' => 'SHIRT-BLK-L', 'selling_price' => '25.00', 'is_active' => false]);
        $this->deleteJson(route('catalog.attributes.colors.destroy', $color))->assertUnprocessable()->assertJsonValidationErrors('color');
        $this->assertDatabaseHas('colors', ['id' => $color->id]);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'color_id' => $color->id]);
        $this->get(route('catalog.attributes.index', ['tab' => 'colors']))->assertOk()->assertSee('مستخدم في أصناف أو صور؛ لا يُحذف.');
    }

    public function test_a_color_with_only_a_product_image_cannot_be_deleted(): void
    {
        $color = Color::create(['name' => 'أسود', 'code' => 'BLK']);
        $product = $this->product();
        ProductImage::create(['product_id' => $product->id, 'color_id' => $color->id, 'image_path' => 'products/colors/kept.png']);
        $this->deleteJson(route('catalog.attributes.colors.destroy', $color))->assertUnprocessable()->assertJsonValidationErrors('color');
        $this->assertDatabaseCount('product_images', 1);
        $this->assertDatabaseHas('colors', ['id' => $color->id]);
    }

    public function test_color_names_are_escaped_in_rows_dialog_payloads_and_feedback(): void
    {
        $color = Color::create(['name' => 'قديم', 'code' => 'OLD']);
        $name = '<img src=x onerror=alert(1)>';
        $this->putJson(route('catalog.attributes.colors.update', $color), ['name' => $name])->assertOk();
        $this->get(route('catalog.attributes.index', ['tab' => 'colors']))->assertOk()->assertSee($name)->assertDontSee($name, false);
    }

    public function test_native_form_submissions_redirect_and_unknown_or_unauthenticated_requests_do_not_mutate_colors(): void
    {
        $color = Color::create(['name' => 'أسود', 'code' => 'BLK']);
        $this->put(route('catalog.attributes.colors.update', $color), ['name' => 'فحمي'])->assertRedirect(route('catalog.attributes.index', ['tab' => 'colors']));
        $this->delete(route('catalog.attributes.colors.destroy', $color))->assertRedirect(route('catalog.attributes.index', ['tab' => 'colors']));
        $this->deleteJson(route('catalog.attributes.colors.destroy', 999))->assertNotFound();
        $kept = Color::create(['name' => 'أبيض', 'code' => 'WHT']);
        auth()->logout();
        $this->putJson(route('catalog.attributes.colors.update', $kept), ['name' => 'خاص'])->assertUnauthorized();
        $this->deleteJson(route('catalog.attributes.colors.destroy', $kept))->assertUnauthorized();
        $this->assertDatabaseHas('colors', ['id' => $kept->id, 'name' => 'أبيض']);
    }

    public function test_color_usage_counts_do_not_add_a_query_for_each_color(): void
    {
        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('catalog.attributes.index', ['tab' => 'colors']))->assertOk();
            $count = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'))->count();
            DB::disableQueryLog();

            return $count;
        };
        Color::create(['name' => 'خاص', 'code' => 'ONE']);
        $single = $countQueries();
        for ($index = 0; $index < 18; $index++) {
            Color::create(['name' => 'لون '.$index, 'code' => 'C'.$index]);
        }
        $this->assertLessThanOrEqual($single + 1, $countQueries());
    }
}
