<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttributesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->salesOwner()->create());
    }

    public function test_one_sidebar_entry_and_page_cover_all_three_sections(): void
    {
        foreach (['categories', 'colors', 'sizes'] as $tab) {
            $this->get(route('catalog.attributes.index', ['tab' => $tab]))->assertOk()
                ->assertSee('التصنيفات والسمات')->assertSee('data-active-tab="'.$tab.'"', false);
        }
        $this->get(route('dashboard.index'))->assertOk()->assertSee(route('catalog.attributes.index'), false);
    }

    public function test_categories_can_be_created_with_description_and_a_parent(): void
    {
        $parent = Category::create(['name' => 'ملابس']);
        $this->postJson(route('catalog.attributes.categories.store'), [
            'name' => '  أطفال  ', 'parent_id' => $parent->id, 'description' => 'ملابس الأطفال',
        ])->assertCreated()->assertJsonPath('tab', 'categories');
        $this->assertDatabaseHas('categories', ['name' => 'أطفال', 'parent_id' => $parent->id, 'description' => 'ملابس الأطفال']);
        $this->get(route('catalog.attributes.index'))->assertOk()->assertSee('أطفال')->assertSee('ملابس الأطفال');
    }

    public function test_duplicate_category_names_are_prevented_under_the_same_parent(): void
    {
        $data = ['name' => 'أطفال'];
        $this->postJson(route('catalog.attributes.categories.store'), $data)->assertCreated();
        $this->postJson(route('catalog.attributes.categories.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('name');
        $parent = Category::create(['name' => 'موسم جديد']);
        $this->postJson(route('catalog.attributes.categories.store'), $data + ['parent_id' => $parent->id])->assertCreated();
        $this->postJson(route('catalog.attributes.categories.store'), $data + ['parent_id' => $parent->id])->assertUnprocessable();
    }

    public function test_category_schema_limits_and_invalid_parents_are_validated(): void
    {
        $this->postJson(route('catalog.attributes.categories.store'), ['name' => str_repeat('x', 101)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(route('catalog.attributes.categories.store'), ['name' => 'ملابس', 'parent_id' => 999])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson(route('catalog.attributes.categories.store'), ['name' => 'ملابس', 'parent_id' => [1]])->assertUnprocessable();
    }

    public function test_a_color_stores_its_exact_degree_and_generates_a_separate_business_code(): void
    {
        $this->postJson(route('catalog.attributes.colors.store'), ['name' => 'لون خاص', 'hex_code' => '#12abef'])->assertCreated();
        $color = Color::firstOrFail();
        $this->assertSame('#12ABEF', $color->hex_code);
        $this->assertMatchesRegularExpression('/^C-[A-Z0-9]{10}$/', $color->code);
        $this->assertSame('#12ABEF', $color->display_hex);
        $this->get(route('catalog.attributes.index', ['tab' => 'colors']))->assertOk()->assertSee('background-color:#12ABEF', false)->assertSee('لون خاص');
    }

    public function test_known_names_receive_a_suggestion_but_explicit_choices_win(): void
    {
        $this->postJson(route('catalog.attributes.colors.store'), ['name' => 'أَحْمَر'])->assertCreated();
        $this->assertSame('#DC2626', Color::firstOrFail()->hex_code);
        $this->postJson(route('catalog.attributes.colors.store'), ['name' => 'navy blue', 'hex_code' => '#112233'])->assertCreated();
        $this->assertDatabaseHas('colors', ['name' => 'navy blue', 'hex_code' => '#112233']);
    }

    public function test_unknown_names_require_a_degree_instead_of_a_fabricated_color(): void
    {
        $this->postJson(route('catalog.attributes.colors.store'), ['name' => 'درجة مخصصة'])->assertUnprocessable()->assertJsonValidationErrors('hex_code');
        $this->assertDatabaseCount('colors', 0);
    }

    public function test_invalid_hex_values_and_duplicate_color_names_or_codes_are_rejected(): void
    {
        foreach (['#fff', 'red', '#12ZZ34', '#123456;background:url(x)'] as $hex) {
            $this->postJson(route('catalog.attributes.colors.store'), ['name' => 'خاص', 'hex_code' => $hex])->assertUnprocessable()->assertJsonValidationErrors('hex_code');
        }
        $data = ['name' => 'أسود', 'hex_code' => '#000000', 'code' => 'blk'];
        $this->postJson(route('catalog.attributes.colors.store'), $data)->assertCreated();
        $this->postJson(route('catalog.attributes.colors.store'), $data)->assertUnprocessable();
        $this->postJson(route('catalog.attributes.colors.store'), array_replace($data, ['name' => 'آخر']))->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_existing_colors_show_a_suggestion_without_overwriting_existing_records(): void
    {
        $known = Color::create(['name' => 'أبيض', 'code' => 'WHT']);
        $unknown = Color::create(['name' => 'خاص قديم', 'code' => 'OLD']);
        $this->get(route('catalog.attributes.index', ['tab' => 'colors']))->assertOk()->assertSee('مقترح من الاسم')->assertSee('background-color:#FFFFFF', false);
        $this->assertNull($known->fresh()->hex_code);
        $this->assertNull($unknown->display_hex);
    }

    public function test_adjusting_a_degree_cannot_change_names_codes_or_skus(): void
    {
        $category = Category::create(['name' => 'ملابس']);
        $color = Color::create(['name' => 'أزرق', 'code' => 'BLU']);
        $size = Size::create(['name' => 'L', 'code' => 'L']);
        $product = Product::create(['name' => 'قميص', 'code' => 'SHIRT', 'category_id' => $category->id]);
        $variant = $product->variants()->create(['color_id' => $color->id, 'size_id' => $size->id, 'sku' => 'SHIRT-BLU-L', 'selling_price' => '25.00']);
        $this->putJson(route('catalog.attributes.colors.appearance', $color), ['hex_code' => '#223344', 'name' => 'تغيير غير مسموح', 'code' => 'OTHER'])->assertOk();
        $this->assertDatabaseHas('colors', ['id' => $color->id, 'name' => 'أزرق', 'code' => 'BLU', 'hex_code' => '#223344']);
        $this->assertSame('SHIRT-BLU-L', $variant->fresh()->sku);
        $this->assertSame('25.00', $variant->fresh()->selling_price);
    }

    public function test_sizes_generate_codes_and_respect_display_order(): void
    {
        $this->postJson(route('catalog.attributes.sizes.store'), ['name' => 'XL', 'sort_order' => 50])->assertCreated();
        $this->assertDatabaseHas('sizes', ['name' => 'XL', 'code' => 'XL', 'sort_order' => 50]);
        $this->postJson(route('catalog.attributes.sizes.store'), ['name' => 'عمر سنتين', 'sort_order' => 10])->assertCreated();
        $arabic = Size::where('name', 'عمر سنتين')->firstOrFail();
        $this->assertMatchesRegularExpression('/^S-[A-Z0-9]{10}$/', $arabic->code);
        $this->get(route('catalog.attributes.index', ['tab' => 'sizes']))->assertOk()->assertSeeInOrder(['عمر سنتين', 'XL']);
        $this->postJson(route('catalog.attributes.sizes.store'), ['name' => 'XL'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(route('catalog.attributes.sizes.store'), ['name' => 'XXL', 'sort_order' => -1])->assertUnprocessable()->assertJsonValidationErrors('sort_order');
    }

    public function test_search_covers_records_beyond_the_first_page_and_preserves_pagination(): void
    {
        for ($index = 0; $index < 25; $index++) {
            Category::create(['name' => 'تصنيف '.str_pad((string) $index, 2, '0', STR_PAD_LEFT)]);
        }
        $this->get(route('catalog.attributes.index', ['page' => 2]))->assertOk()->assertSee('تصنيف 00');
        $this->get(route('catalog.attributes.index', ['tab' => 'categories', 'q' => 'تصنيف 00']))->assertOk()->assertSee('تصنيف 00')->assertViewHas('entries', fn ($entries): bool => $entries->total() === 1 && $entries->first()->name === 'تصنيف 00');
        $this->getJson(route('catalog.attributes.index', ['tab' => 'invalid']))->assertUnprocessable();
    }

    public function test_new_attributes_are_immediately_available_for_opening_stock(): void
    {
        $branch = DB::table('branches')->insertGetId(['name' => 'الرئيسي']);
        $this->postJson(route('catalog.attributes.categories.store'), ['name' => 'أطفال'])->assertCreated();
        $this->postJson(route('catalog.attributes.colors.store'), ['name' => 'أحمر', 'hex_code' => '#DC2626'])->assertCreated();
        $this->postJson(route('catalog.attributes.sizes.store'), ['name' => 'L'])->assertCreated();
        $options = $this->getJson(route('inventory.opening-stock.options'))->assertOk();
        $this->assertSame('#DC2626', $options->json('colors.0.hex_code'));
        $this->postJson(route('inventory.opening-stock.store'), [
            'client_uuid' => (string) Str::uuid(), 'branch_id' => $branch, 'opening_date' => now()->toDateString(),
            'rows' => [['name' => 'قميص', 'category_id' => Category::firstOrFail()->id, 'color_id' => Color::firstOrFail()->id,
                'size_id' => Size::firstOrFail()->id, 'qty' => 2, 'cost' => '5.00', 'price' => '10.00']],
        ])->assertCreated();
    }

    public function test_the_existing_api_and_quick_add_can_share_visual_color_data(): void
    {
        $this->postJson('/api/colors', ['name' => 'أبيض', 'code' => 'WHT'])->assertCreated();
        $this->getJson('/api/colors')->assertOk()->assertJsonPath('data.0.hex_code', '#FFFFFF');
        $this->postJson(route('inventory.opening-stock.options.store'), ['type' => 'color', 'name' => 'خاص', 'code' => 'CUSTOM', 'hex_code' => '#445566'])->assertCreated();
        $this->assertDatabaseHas('colors', ['name' => 'خاص', 'hex_code' => '#445566']);
    }

    public function test_native_form_submission_can_redirect_with_a_success_message(): void
    {
        $this->post(route('catalog.attributes.categories.store'), ['name' => 'أطفال'])
            ->assertRedirect(route('catalog.attributes.index', ['tab' => 'categories']))->assertSessionHas('success');
    }

    public function test_category_parent_and_product_counts_do_not_cause_n_plus_one_queries(): void
    {
        $parent = Category::create(['name' => 'رئيسي']);
        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('catalog.attributes.index'))->assertOk();
            $count = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'))->count();
            DB::disableQueryLog();

            return $count;
        };
        Category::create(['name' => 'فرعي', 'parent_id' => $parent->id]);
        $small = $countQueries();
        for ($index = 0; $index < 18; $index++) {
            Category::create(['name' => 'فرعي '.$index, 'parent_id' => $parent->id]);
        }
        $this->assertLessThanOrEqual($small + 1, $countQueries());
    }
}
