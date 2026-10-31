<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\Sales\SalesAccess;
use App\Support\Search\Code128;
use Database\Factories\BranchFactory;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\SalesTestCase;

class UnifiedProductSearchTest extends SalesTestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') === 'mysql' && ! in_array(getenv('DB_DATABASE'), ['appstore_fabric_swatch_test', 'appstore_golive_20261031_tests'], true)) {
            throw new \LogicException('Only the approved isolated database may be used.');
        }
        parent::setUp();
    }

    public function test_name_code_sku_color_size_and_arabic_digits_use_the_same_scoped_search(): void
    {
        $variant = $this->stock('35', '20', 10, 'BL100');
        $variant->product->update(['name' => 'قميص قطني']);
        foreach (['قطني', 'BL100', $variant->sku, 'أسود', 'L', 'BL١٠٠'] as $term) {
            $this->getJson(route('search.products', ['q' => $term]))->assertOk()->assertJsonPath('data.0.product_variant_id', $variant->id)->assertJsonPath('data.0.quantity', 10);
        }
    }

    public function test_exact_sku_ranks_before_exact_code_prefix_and_product_name(): void
    {
        $exact = $this->stock('35', '20', 10, 'ORIGINAL');
        $exact->update(['sku' => 'BL100']);
        $code = $this->stock('35', '20', 10, 'BL100');
        $prefix = $this->stock('35', '20', 10, 'BL100-OTHER');
        $named = $this->stock('35', '20', 10, 'NAMED');
        $named->product->update(['name' => 'BL100']);
        $response = $this->getJson(route('search.products', ['q' => 'BL100']))->assertOk();
        $this->assertSame([$exact->id, $code->id, $prefix->id, $named->id], array_column($response->json('data'), 'product_variant_id'));
        $this->getJson(route('sales.catalog', ['branch_id' => $this->branchId, 'q' => 'BL100']))->assertOk()->assertJsonPath('data.0.id', $exact->product_id);
    }

    public function test_exact_scan_does_not_return_prefix_sku_variants(): void
    {
        $variant = $this->stock('35', '20', 10, 'SCAN');
        $this->stock('35', '20', 10, 'SCAN-EXTRA');
        $this->getJson(route('search.products', ['q' => $variant->sku, 'match' => 'exact']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', $variant->sku);
    }

    public function test_color_and_size_filters_are_applied_to_the_actual_variant(): void
    {
        $variant = $this->stock();
        $this->getJson(route('search.products', ['color_id' => $variant->color_id, 'size_id' => $variant->size_id]))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(route('search.products', ['size_id' => 999999]))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_viewer_cannot_see_cost_or_foreign_branch_stock_or_sale_actions(): void
    {
        $variant = $this->stock();
        $foreignBranch = BranchFactory::new()->create();
        $foreign = $this->stock('35', '20', 5, 'FOREIGN');
        Inventory::where('product_variant_id', $foreign->id)->update(['branch_id' => $foreignBranch->id]);
        $viewer = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('inventory_viewer')->id]);
        $this->actingAs($viewer);
        $this->getJson(route('search.products'))->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.average_cost')->assertJsonPath('data.0.sale_url', null);
        $this->getJson(route('search.products', ['branch_id' => $foreignBranch->id]))->assertOk()->assertJsonCount(0, 'data');
        $this->get(route('search.products.labels', ['variants' => [$foreign->id], 'branch_id' => $foreignBranch->id]))->assertNotFound();
        $this->get(route('search.products.labels', ['variants' => [$variant->id], 'branch_id' => $this->branchId]))->assertOk()->assertDontSee('متوسط التكلفة');
    }

    public function test_search_and_print_require_explicit_permissions_not_just_login(): void
    {
        $variant = $this->stock();
        $this->actingAs(User::factory()->create());
        $this->getJson(route('search.products', ['q' => $variant->sku]))->assertForbidden();
        $this->get(route('search.products.labels', ['variants' => [$variant->id]]))->assertForbidden();
    }

    public function test_read_results_do_not_modify_stock_documents_or_financial_records(): void
    {
        $variant = $this->stock();
        $before = DB::table('inventories')->where('product_variant_id', $variant->id)->first();
        $counts = [DB::table('stock_movements')->count(), DB::table('journal_entries')->count(), DB::table('audit_events')->count()];
        $this->getJson(route('search.products', ['q' => $variant->sku]))->assertOk();
        $this->get(route('search.index', ['type' => 'products', 'q' => $variant->sku]))->assertOk();
        $this->get(route('search.products.labels', ['variants' => [$variant->id]]))->assertOk();
        $this->assertEquals($before, DB::table('inventories')->where('product_variant_id', $variant->id)->first());
        $this->assertSame($counts, [DB::table('stock_movements')->count(), DB::table('journal_entries')->count(), DB::table('audit_events')->count()]);
    }

    public function test_existing_thumbnail_is_reused_and_zero_quantity_is_not_replaced_by_cached_stock(): void
    {
        $variant = $this->stock();
        ProductImage::create(['product_id' => $variant->product_id, 'color_id' => $variant->color_id, 'image_path' => 'existing/optimized.webp', 'thumbnail_path' => 'existing/thumbnail.webp']);
        $this->getJson(route('search.products'))->assertOk()->assertJsonPath('data.0.quantity', 10)->assertJsonPath('data.0.image_url', Storage::disk('public')->url('existing/thumbnail.webp'));
        Inventory::where('product_variant_id', $variant->id)->update(['quantity' => 0]);
        $this->getJson(route('search.products'))->assertOk()->assertJsonPath('data.0.quantity', 0);
    }

    public function test_query_count_is_bounded_as_result_count_grows(): void
    {
        $this->stock();
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(route('search.products'))->assertOk();
            $number = count(array_filter(DB::getQueryLog(), fn ($entry) => str_starts_with(strtolower($entry['query']), 'select')));
            DB::disableQueryLog();

            return $number;
        };
        $single = $count();
        for ($index = 0; $index < 12; $index++) {
            $this->stock('35', '20', 1, 'BULK'.$index);
        }
        $multiple = $count();
        $this->assertLessThanOrEqual($single + 1, $multiple);
        if (getenv('PRODUCT_SEARCH_PREVIEW') === '1') {
            file_put_contents(storage_path('app/reports/product-search-query-count.json'), json_encode(['driver' => DB::connection()->getDriverName(), 'one_result' => $single, 'thirteen_results' => $multiple], JSON_PRETTY_PRINT));
        }
    }

    public function test_current_setup_sale_block_is_reported_and_never_closed_by_search(): void
    {
        $variant = $this->stock();
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => null]);
        $this->getJson(route('search.products', ['q' => $variant->sku]))->assertOk()->assertJsonPath('data.0.setup_open', true);
        $this->assertNull(DB::table('branches')->where('id', $this->branchId)->value('opening_stock_closed_at'));
    }

    public function test_code128_has_the_standard_start_payload_checksum_and_stop_for_a_known_vector(): void
    {
        $barcode = new Code128;
        $this->assertSame([104, 33, 34, 17, 18, 13, 56, 8, 106], $barcode->symbols('AB12-X'));
        $document = new DOMDocument;
        $document->loadXML($barcode->svg('AB12-X'));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');
        $rectangles = $xpath->query('//s:g/s:rect');
        $widths = '';
        foreach ($rectangles as $index => $rectangle) {
            $widths .= $rectangle->getAttribute('width');
            if ($index + 1 < $rectangles->length) {
                $next = $rectangles->item($index + 1);
                $widths .= (int) $next->getAttribute('x') - (int) $rectangle->getAttribute('x') - (int) $rectangle->getAttribute('width');
            }
        }
        $this->assertSame('2112141113231311231232212232111221323311211322122331112', $widths);
    }

    public function test_ascii_sku_is_encoded_without_changing_case_spaces_or_leading_zeroes(): void
    {
        $sku = '00-Abc 12-X';
        $symbols = (new Code128)->symbols($sku);
        $decoded = implode('', array_map(fn ($value) => chr($value + 32), array_slice($symbols, 1, -2)));
        $this->assertSame($sku, $decoded);
        $this->assertTrue(Code128::supports($sku));
        $this->assertFalse(Code128::supports('يسبسيبسب'));
        $this->assertFalse(Code128::supports("ABC\n123"));
    }

    public function test_unsupported_sku_is_never_rewritten_and_labels_show_a_clear_warning(): void
    {
        $variant = $this->stock();
        $variant->update(['sku' => 'يسبسيبسب']);
        $this->getJson(route('search.products', ['q' => 'يسبسيبسب']))->assertOk()->assertJsonPath('data.0.barcode_supported', false)->assertJsonPath('data.0.sku', 'يسبسيبسب');
        $this->get(route('search.products.labels', ['variants' => [$variant->id]]))->assertOk()->assertSee('لا يمكن طباعة هذا SKU')->assertSee('يسبسيبسب');
        $this->assertSame('يسبسيبسب', $variant->fresh()->sku);
    }

    public function test_label_request_is_bounded_and_does_not_allow_unlisted_ids(): void
    {
        $variant = $this->stock();
        $this->getJson(route('search.products.labels', ['variants' => [$variant->id], 'copies' => 999]))->assertUnprocessable();
        $this->getJson(route('search.products.labels', ['variants' => ['../../.env']]))->assertUnprocessable();
        $this->get(route('search.products.labels', ['variants' => [$variant->id, 999999]]))->assertNotFound();
    }

    public function test_scanner_controls_are_progressive_and_never_claim_ocr_or_visual_is_active(): void
    {
        $this->get(route('search.index'))->assertOk()->assertSee('مسح باركود')->assertSee('قراءة الكود المكتوب')->assertSee('غير مفعّل');
        $this->get(route('sales.cashier'))->assertOk()->assertSee('data-scan-mode', false);
    }

    public function test_ocr_candidate_resolution_is_exact_and_never_guesses_similar_characters(): void
    {
        $letter = $this->stock('35', '20', 2, 'LETTER');
        $letter->update(['sku' => 'ABO-1']);
        $digit = $this->stock('35', '20', 2, 'DIGIT');
        $digit->update(['sku' => 'AB0-1']);
        $this->postJson(route('search.products.codes'), ['codes' => ['ABO-1']])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'ABO-1');
        $this->postJson(route('search.products.codes'), ['codes' => ['ABO']])->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(route('search.products.codes'), ['codes' => ['ABO-1', 'AB0-1']])->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('selected_variant_id');
    }

    public function test_ocr_candidate_resolution_is_bounded_authorized_and_branch_scoped(): void
    {
        $variant = $this->stock();
        $branch = BranchFactory::new()->create();
        $viewer = User::factory()->create(['branch_id' => $branch->id, 'role_id' => SalesAccess::provisionRole('inventory_viewer')->id]);
        $this->actingAs($viewer);
        $this->postJson(route('search.products.codes'), ['codes' => [$variant->sku]])->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(route('search.products.codes'), ['codes' => array_fill(0, 13, 'SKU')])->assertUnprocessable();
        $this->actingAs(User::factory()->create());
        $this->postJson(route('search.products.codes'), ['codes' => [$variant->sku]])->assertForbidden();
    }

    public function test_inventory_viewer_search_markup_contains_real_variant_results_without_cost(): void
    {
        $variant = $this->stock();
        $this->stock('35', '20', 3, 'PANTS');
        $viewer = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('inventory_viewer')->id]);
        $this->actingAs($viewer);
        $response = $this->get(route('search.index', ['type' => 'products']))->assertOk()->assertSee($variant->sku)->assertDontSee('average_cost');
        if (getenv('PRODUCT_SEARCH_PREVIEW') === '1') {
            $directory = storage_path('app/reports/product-search-preview');
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            file_put_contents($directory.'/search.html', $response->getContent());
            file_put_contents($directory.'/products.json', $this->getJson(route('search.products'))->assertOk()->getContent());
            file_put_contents($directory.'/labels.html', $this->get(route('search.products.labels', ['variants' => [$variant->id]]))->assertOk()->getContent());
        }
    }
}
