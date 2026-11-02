<?php

namespace Tests\Feature;

use App\Actions\Store\StorePricing;
use App\Models\Color;
use App\Models\DeliveryZone;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\StoreListing;
use App\Models\StoreSection;
use App\Models\StoreSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestCase;

class StorefrontExperienceTest extends SalesTestCase
{
    public function test_published_section_is_browsable_without_store_settings_while_stock_and_checkout_remain_unavailable(): void
    {
        $variant = $this->stock('75.00', '20.00', 25, '014');
        $hidden = $this->stock('80.00', '20.00', 3, 'HIDDEN');
        $section = StoreSection::factory()->create(['name' => 'بناتي', 'slug' => 'girls']);
        StoreListing::factory()->create(['product_id' => $variant->product_id, 'store_section_id' => $section->id, 'slug' => 'published-shirt']);
        StoreListing::factory()->create(['product_id' => $hidden->product_id, 'store_section_id' => $section->id, 'slug' => 'hidden-shirt', 'is_published' => false]);
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => null]);
        DB::table('sales_controls')->where('id', 1)->update(['activated_at' => null]);

        $response = $this->get('/shop/sections/girls')->assertOk()->assertSee('منتج 014')->assertDontSee('منتج HIDDEN');
        $this->assertSame(1, $response->viewData('entries')->total());
        $this->assertCount(1, $response->viewData('entries')->first()->product->variants);
        $this->assertCount(0, $response->viewData('entries')->first()->product->variants->first()->inventories);
        $this->get('/shop/sections/girls?color_id='.$variant->color_id.'&size_id='.$variant->size_id)->assertOk()->assertSee('منتج 014');
        $product = $this->get('/shop/products/published-shirt')->assertOk()->assertDontSee('average_cost')->assertDontSee('reserved_quantity');
        $this->assertFalse((bool) $product->viewData('variantOptions')->first()['available']);
        $this->get('/shop/products/hidden-shirt')->assertNotFound();
        $this->get('/shop/checkout')->assertOk()->assertSee('data-ready="0"', false);
        $this->postJson('/shop/quote', ['delivery_zone_id' => DeliveryZone::factory()->create()->id,
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('checkout');
        $this->assertDatabaseCount('store_settings', 0);
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_public_variant_stock_stays_scoped_to_the_configured_branch(): void
    {
        $data = $this->catalogue();
        $otherColor = Color::create(['name' => 'أزرق آخر', 'code' => 'OTHER-BLUE']);
        $otherSize = Size::create(['name' => 'مقاس آخر', 'code' => 'OTHER-SIZE']);
        $otherBranch = DB::table('branches')->insertGetId(['name' => 'فرع آخر']);
        $otherVariant = ProductVariant::create(['product_id' => $data['variant']->product_id,
            'color_id' => $otherColor->id, 'size_id' => $otherSize->id, 'sku' => 'OTHER-BRANCH-ONLY', 'selling_price' => '99.00']);
        Inventory::create(['branch_id' => $otherBranch, 'product_variant_id' => $otherVariant->id,
            'quantity' => 9, 'average_cost' => '20.00', 'value_minor' => 18000]);

        $response = $this->get('/shop/sections/our-selection')->assertOk()->assertDontSee('أزرق آخر');
        $variants = $response->viewData('entries')->first()->product->variants;
        $this->assertSame([$data['variant']->id], $variants->modelKeys());
        $this->assertSame([$this->branchId], $variants->first()->inventories->pluck('branch_id')->all());
        $this->get('/shop/sections/our-selection?color_id='.$otherColor->id)->assertOk()->assertDontSee('منتج PUBLIC-SHIRT');
        $this->get('/shop/products/public-shirt')->assertOk()->assertDontSee('OTHER-BRANCH-ONLY');
    }

    private function catalogue(): array
    {
        $variant = $this->stock('75.00', '20.00', 10, 'PUBLIC-SHIRT');
        $settings = StoreSetting::factory()->create(['branch_id' => $this->branchId, 'name' => 'متجرنا', 'seo_title' => 'أناقة كل يوم', 'seo_description' => 'تشكيلة من الملابس بألوان ومقاسات متنوعة.']);
        $section = StoreSection::factory()->create(['name' => 'مختاراتنا', 'slug' => 'our-selection']);
        $listing = StoreListing::factory()->create(['product_id' => $variant->product_id, 'store_section_id' => $section->id, 'slug' => 'public-shirt']);
        $zone = DeliveryZone::factory()->create();

        return compact('variant', 'settings', 'section', 'listing', 'zone');
    }

    public function test_home_and_basket_render_without_settings_products_or_banners_and_do_not_create_data(): void
    {
        $this->get('/shop')->assertOk()->assertSee('shop-header', false)->assertSee('shop-footer', false)
            ->assertSee('تشكيلتنا تتجدّد')->assertDontSee('تعذر إتمام الطلب')->assertSee('noindex,follow', false);
        $this->get('/shop/checkout')->assertOk()->assertSee('data-checkout-paused', false)->assertSee('disabled', false);
        $this->get('/shop/sitemap.xml')->assertOk()->assertSee('/shop')->assertDontSee('/site-admin')->assertDontSee('/checkout');
        $this->assertDatabaseCount('store_settings', 0);
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_published_catalogue_remains_browsable_when_shop_is_closed_or_sales_setup_is_open(): void
    {
        $data = $this->catalogue();
        $data['settings']->update(['is_open' => false]);
        $this->get('/shop')->assertOk()->assertSee('منتج PUBLIC-SHIRT');
        $this->get('/shop/products/public-shirt')->assertOk()->assertSee('اللون')->assertSee('المقاس')->assertSee('لا توجد صورة');
        $data['settings']->update(['is_open' => true]);
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => null]);
        $this->get('/shop')->assertOk()->assertSee('منتج PUBLIC-SHIRT');
        $this->get('/shop/checkout')->assertOk()->assertSee('data-ready="0"', false)->assertSee('data-checkout-paused', false);
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_checkout_quote_and_direct_order_cannot_bypass_open_setup_or_inactive_sales(): void
    {
        $data = $this->catalogue();
        $rows = [['product_variant_id' => $data['variant']->id, 'quantity' => 1]];
        $pricing = app(StorePricing::class);
        $quote = $pricing->quote(['delivery_zone_id' => $data['zone']->id, 'rows' => $rows]);
        $input = ['client_uuid' => (string) Str::uuid(), 'customer_name' => 'عميل اختبار', 'phone' => '0599000000', 'address' => 'عنوان اختبار معزول',
            'delivery_zone_id' => $data['zone']->id, 'rows' => $rows, 'accepted' => true,
            'policy_version' => hash('sha256', $data['settings']->policy), 'quote_revision' => $pricing->revision($quote)];
        $journals = DB::table('journal_entries')->count();
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => null]);
        $this->postJson('/shop/quote', $input)->assertUnprocessable()->assertJsonValidationErrors('checkout');
        $this->postJson('/shop/orders', $input)->assertUnprocessable()->assertJsonValidationErrors('checkout');
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => now()]);
        DB::table('sales_controls')->where('id', 1)->update(['activated_at' => null]);
        $this->get('/shop/checkout')->assertOk()->assertSee('data-ready="0"', false);
        $this->postJson('/shop/orders', $input)->assertUnprocessable()->assertJsonValidationErrors('checkout');
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('store_reservations', 0);
        $this->assertSame($journals, DB::table('journal_entries')->count());
        $this->assertSame(10, (int) Inventory::where('product_variant_id', $data['variant']->id)->value('quantity'));
    }

    public function test_seo_is_dynamic_escaped_and_present_in_actual_html_and_sitemap(): void
    {
        $data = $this->catalogue();
        $data['listing']->update(['seo_title' => 'قميص أنيق', 'seo_description' => 'وصف القطعة من بيانات المتجر']);
        $this->get('/shop')->assertOk()->assertSee('<title>أناقة كل يوم</title>', false)
            ->assertSee('property="og:site_name"', false)->assertSee('application/ld+json', false)->assertSee('WebSite')->assertSee('Store');
        $response = $this->get('/shop/products/public-shirt')->assertOk()->assertSee('<title>قميص أنيق</title>', false)
            ->assertSee('وصف القطعة من بيانات المتجر')->assertSee(route('store.product', 'public-shirt'))
            ->assertDontSee('average_cost')->assertDontSee('minimum_selling_price');
        preg_match_all('/<script type="application\\/ld\\+json">(.*?)<\\/script>/s', $response->getContent(), $graphs);
        $productGraph = json_decode($graphs[1][1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('https://schema.org/InStock', $productGraph['offers'][0]['availability']);
        $this->assertSame(2, substr_count($response->getContent(), 'application/ld+json'));
        $this->get('/shop?q=PUBLIC-SHIRT')->assertOk()->assertSee('noindex,follow', false);
        $this->get('/shop/sitemap.xml')->assertOk()->assertSee('/shop/products/public-shirt')->assertSee('/shop/sections/our-selection')->assertDontSee('/site-admin');
        $this->assertSame(1, $data['settings']->getKey());
        $data['settings']->update(['seo_title' => '<script>alert(1)</script>']);
        $this->assertDatabaseHas('store_settings', ['id' => 1, 'seo_title' => '<script>alert(1)</script>']);
        $this->get('/shop')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_filters_require_color_and_size_on_the_same_published_variant_and_hide_unpublished_products(): void
    {
        $data = $this->catalogue();
        $red = Color::create(['name' => 'أحمر', 'code' => 'RED']);
        $small = Size::create(['name' => 'S', 'code' => 'S']);
        $variant = ProductVariant::create(['product_id' => $data['variant']->product_id, 'color_id' => $red->id, 'size_id' => $small->id,
            'sku' => 'PUBLIC-RED-S', 'selling_price' => '75.00']);
        Inventory::create(['branch_id' => $this->branchId, 'product_variant_id' => $variant->id, 'quantity' => 0, 'average_cost' => '0.00', 'value_minor' => 0]);
        $this->get('/shop?color_id='.$data['variant']->color_id.'&size_id='.$small->id)->assertOk()->assertDontSee('منتج PUBLIC-SHIRT')->assertSee('لم نجد قطعًا');
        $this->get('/shop?color_id='.$red->id.'&size_id='.$small->id)->assertOk()->assertSee('منتج PUBLIC-SHIRT');
        $data['listing']->update(['is_published' => false]);
        $this->get('/shop')->assertOk()->assertDontSee('منتج PUBLIC-SHIRT');
        $this->get('/shop/products/public-shirt')->assertNotFound()->assertSee('لم نجد الصفحة')->assertDontSee('تعذر إتمام الطلب');
    }

    public function test_catalogue_query_count_does_not_grow_per_product_and_configured_checkout_still_works(): void
    {
        $data = $this->catalogue();
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/shop')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $one = $count();
        for ($index = 0; $index < 19; $index++) {
            $variant = $this->stock('50.00', '20.00', 2, 'PUBLIC-'.$index);
            StoreListing::factory()->create(['product_id' => $variant->product_id]);
        }
        $many = $count();
        $this->assertLessThanOrEqual($one + 3, $many, 'Public catalogue must not query once per product.');
        $this->get('/shop/checkout')->assertOk()->assertSee('data-ready="1"', false)->assertDontSee('data-checkout-paused', false);
        $this->postJson('/shop/quote', ['delivery_zone_id' => $data['zone']->id, 'rows' => [['product_variant_id' => $data['variant']->id, 'quantity' => 1]]])
            ->assertOk()->assertJsonPath('total', '95.00');
    }
}
