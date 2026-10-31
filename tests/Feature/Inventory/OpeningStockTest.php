<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\OpeningStock\PostOpeningStockAction;
use App\Models\Category;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\OpeningStockEntry;
use App\Models\OpeningStockItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OpeningStockTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;

    private Category $category;

    private Color $color;

    private Size $size;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->salesOwner()->create());
        Model::preventLazyLoading();
        Storage::fake('public');
        $this->branchId = DB::table('branches')->insertGetId(['name' => 'الفرع الأول']);
        $this->category = Category::create(['name' => 'ملابس']);
        $this->color = Color::create(['name' => 'أسود', 'code' => 'BLK']);
        $this->size = Size::create(['name' => 'L', 'code' => 'L']);
    }

    private function row(array $overrides = []): array
    {
        return array_replace(['name' => 'قميص قطني', 'code' => 'SHIRT-01', 'category_id' => $this->category->id,
            'color_id' => $this->color->id, 'size_id' => $this->size->id, 'qty' => 3, 'cost' => '0.10', 'price' => '25.50'], $overrides);
    }

    private function payload(?array $rows = null, array $overrides = []): array
    {
        return array_replace(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId,
            'opening_date' => now()->toDateString(), 'notes' => 'رصيد بدء العمل', 'rows' => $rows ?? [$this->row()]], $overrides);
    }

    private function existingVariant(): ProductVariant
    {
        $product = Product::create(['name' => 'قميص قطني', 'code' => 'SHIRT-01', 'category_id' => $this->category->id]);

        return $product->variants()->create(['color_id' => $this->color->id, 'size_id' => $this->size->id, 'sku' => 'SHIRT-01-BLK-L', 'selling_price' => '25.50']);
    }

    public function test_it_posts_a_complete_document_with_the_authenticated_actor_and_without_financial_payments(): void
    {
        $size = Size::create(['name' => 'XL', 'code' => 'XL']);
        $response = $this->postJson(route('inventory.opening-stock.store'), $this->payload([
            $this->row(), $this->row(['size_id' => $size->id, 'qty' => 2, 'cost' => '10.25']),
        ]));
        $response->assertCreated()->assertJsonPath('total_cost', '20.80')->assertJsonPath('total_quantity', 5)->assertJsonPath('replayed', false);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 2);
        $this->assertDatabaseCount('opening_stock_entries', 1);
        $this->assertDatabaseCount('opening_stock_items', 2);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('inventories', 2);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseHas('opening_stock_entries', ['status' => 'approved', 'created_by' => auth()->id(), 'total_quantity' => 5]);
        $this->assertDatabaseHas('stock_movements', ['source_type' => (new OpeningStockItem)->getMorphClass(), 'movement_type' => 'opening_stock', 'quantity_change' => 3, 'user_id' => auth()->id()]);
        $this->assertSame(2, StockMovement::distinct()->count('source_id'));
        $this->assertDatabaseHas('product_variants', ['sku' => 'SHIRT-01-BLK-XL']);
    }

    public function test_preview_checks_the_same_rules_and_calculates_totals_without_writing(): void
    {
        $this->postJson(route('inventory.opening-stock.preview'), $this->payload())
            ->assertOk()->assertJsonPath('total_cost', '0.30')->assertJsonPath('rows.0.line_total', '0.30');
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('inventories', 0);
        $this->assertDatabaseCount('opening_stock_entries', 0);
        $this->assertDatabaseHas('product_code_counters', ['last_number' => 0]);
    }

    public function test_client_totals_are_ignored_and_decimal_values_are_not_truncated(): void
    {
        $data = $this->payload([$this->row(['cost' => 12.34, 'qty' => 3])], ['total_cost' => '1.00']);
        $this->postJson(route('inventory.opening-stock.store'), $data)->assertCreated()->assertJsonPath('total_cost', '37.02');
    }

    public function test_retrying_the_same_request_returns_the_document_without_adding_stock(): void
    {
        $payload = $this->payload();
        $first = $this->postJson(route('inventory.opening-stock.store'), $payload)->assertCreated();
        $this->postJson(route('inventory.opening-stock.store'), $payload)->assertOk()
            ->assertJsonPath('number', $first->json('number'))->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('inventories', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(3, Inventory::firstOrFail()->quantity);
        $payload['rows'][0]['qty'] = 10;
        $this->postJson(route('inventory.opening-stock.store'), $payload)->assertConflict();
        $this->assertDatabaseCount('opening_stock_entries', 1);
    }

    public function test_duplicate_rows_and_aliases_are_rejected_without_partial_writes(): void
    {
        $this->existingVariant();
        $this->postJson(route('inventory.opening-stock.store'), $this->payload([$this->row(), $this->row(['code' => null])]))
            ->assertUnprocessable()->assertJsonValidationErrors('rows.1.name');
        $this->assertDatabaseCount('opening_stock_entries', 0);
        $this->assertDatabaseCount('inventories', 0);
    }

    public function test_new_products_get_unique_server_generated_codes_and_consistent_skus(): void
    {
        $this->postJson(route('inventory.opening-stock.store'), $this->payload([
            $this->row(['code' => null]), $this->row(['code' => null, 'name' => 'منتج ثان']),
        ]))->assertCreated();
        $products = Product::with('variants')->get();
        $this->assertCount(2, $products->pluck('code')->unique());
        foreach ($products as $product) {
            $this->assertMatchesRegularExpression('/^P-\d{8,}$/', $product->code);
            $this->assertSame($product->code.'-BLK-L', $product->variants->first()->sku);
        }
    }

    public function test_generated_codes_skip_codes_already_reserved_by_manual_products(): void
    {
        Product::create(['name' => 'قديم', 'code' => 'P-00000001', 'category_id' => $this->category->id]);
        $this->postJson(route('inventory.opening-stock.store'), $this->payload([
            $this->row(['code' => null]), $this->row(['name' => 'يدوي', 'code' => 'P-00000002']),
        ]))->assertCreated();
        $this->assertSame(3, Product::distinct()->count('code'));
    }

    public function test_opening_stock_never_overwrites_an_existing_balance_even_if_zero(): void
    {
        $variant = $this->existingVariant();
        Inventory::create(['branch_id' => $this->branchId, 'product_variant_id' => $variant->id, 'quantity' => 0, 'average_cost' => '7.50']);
        $this->postJson(route('inventory.opening-stock.store'), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('rows.0.qty');
        $this->assertDatabaseHas('inventories', ['quantity' => 0, 'average_cost' => '7.50']);
        $this->assertDatabaseCount('opening_stock_entries', 0);
    }

    public function test_an_existing_variant_can_be_opened_in_another_branch_without_changing_its_price(): void
    {
        $variant = $this->existingVariant();
        Inventory::create(['branch_id' => $this->branchId, 'product_variant_id' => $variant->id, 'quantity' => 8, 'average_cost' => '7.50']);
        $other = DB::table('branches')->insertGetId(['name' => 'الفرع الثاني']);
        $this->postJson(route('inventory.opening-stock.store'), $this->payload(null, ['branch_id' => $other]))->assertCreated();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseHas('inventories', ['branch_id' => $this->branchId, 'quantity' => 8, 'average_cost' => '7.50']);
        $this->assertDatabaseHas('inventories', ['branch_id' => $other, 'quantity' => 3, 'average_cost' => '0.10']);
        $this->assertSame('25.50', $variant->fresh()->selling_price);
    }

    public function test_existing_variant_price_changes_are_rejected(): void
    {
        $this->existingVariant();
        $this->postJson(route('inventory.opening-stock.store'), $this->payload([$this->row(['price' => '99.00'])]))
            ->assertUnprocessable()->assertJsonValidationErrors('rows.0.price');
    }

    public function test_existing_code_cannot_be_reused_with_a_different_product_name(): void
    {
        $this->existingVariant();
        $this->postJson(route('inventory.opening-stock.store'), $this->payload([$this->row(['name' => 'اسم آخر'])]))
            ->assertUnprocessable()->assertJsonValidationErrors('rows.0.code');
    }

    public function test_ambiguous_names_require_the_product_code(): void
    {
        $this->existingVariant();
        Product::create(['name' => 'قميص قطني', 'code' => 'SECOND', 'category_id' => $this->category->id]);
        $this->postJson(route('inventory.opening-stock.store'), $this->payload([$this->row(['code' => null])]))
            ->assertUnprocessable()->assertJsonValidationErrors('rows.0.code');
    }

    public function test_inactive_references_and_future_dates_are_rejected(): void
    {
        DB::table('colors')->where('id', $this->color->id)->update(['is_active' => false]);
        $this->postJson(route('inventory.opening-stock.store'), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('rows.0.color_id');
        $this->postJson(route('inventory.opening-stock.store'), $this->payload(null, ['opening_date' => now()->addDay()->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors('opening_date');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_fractional_quantities_negative_costs_and_excess_precision_are_rejected(): void
    {
        foreach ([['qty' => '1.5'], ['cost' => '-1'], ['cost' => '0.001'], ['price' => '1e3'], ['qty' => 1000001]] as $invalid) {
            $this->postJson(route('inventory.opening-stock.store'), $this->payload([$this->row($invalid)]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('opening_stock_entries', 0);
    }

    public function test_multipart_json_preserves_large_batches_without_php_input_variable_limits(): void
    {
        $rows = [];
        for ($index = 0; $index < 120; $index++) {
            $rows[] = $this->row(['name' => 'منتج '.$index, 'code' => null, 'qty' => 1]);
        }
        $data = $this->payload($rows, ['image_rows' => []]);
        $this->withHeader('Accept', 'application/json')->post(route('inventory.opening-stock.store'), ['payload' => json_encode($data)])
            ->assertCreated()->assertJsonPath('total_quantity', 120)->assertJsonPath('total_cost', '12.00');
        $this->assertDatabaseCount('opening_stock_items', 120);
    }

    public function test_missing_multipart_images_are_rejected_instead_of_silently_dropped(): void
    {
        $data = $this->payload(null, ['image_rows' => [0]]);
        $this->withHeader('Accept', 'application/json')->post(route('inventory.opening-stock.store'), ['payload' => json_encode($data)])
            ->assertUnprocessable()->assertJsonValidationErrors('rows');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_a_color_image_is_stored_and_retries_do_not_duplicate_it(): void
    {
        $file = UploadedFile::fake()->image('shirt.png');
        $payload = $this->payload(null, ['image_rows' => [0]]);
        $request = ['payload' => json_encode($payload), 'images' => [0 => $file]];
        $this->withHeader('Accept', 'application/json')->post(route('inventory.opening-stock.store'), $request)->assertCreated();
        $this->withHeader('Accept', 'application/json')->post(route('inventory.opening-stock.store'), $request)->assertOk();
        $this->assertDatabaseCount('product_images', 1);
        Storage::disk('public')->assertExists(ProductImage::firstOrFail()->image_path);
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_a_failure_after_upload_rolls_back_everything_and_removes_the_image(): void
    {
        ProductImage::created(function (): void {
            throw new RuntimeException('Simulated failure after upload');
        });
        try {
            app(PostOpeningStockAction::class)->execute($this->payload([$this->row(['image' => UploadedFile::fake()->image('shirt.png')])]));
            $this->fail('Expected posting failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure after upload', $exception->getMessage());
        } finally {
            ProductImage::flushEventListeners();
        }
        foreach (['products', 'product_variants', 'inventories', 'opening_stock_entries', 'opening_stock_items', 'stock_movements', 'product_images'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_saved_document_snapshots_survive_product_changes_and_pages_render_without_lazy_loading(): void
    {
        $this->postJson(route('inventory.opening-stock.store'), $this->payload())->assertCreated();
        Product::firstOrFail()->update(['name' => 'اسم جديد']);
        ProductVariant::firstOrFail()->update(['selling_price' => '77.00']);
        $entry = OpeningStockEntry::firstOrFail();
        $this->get(route('inventory.opening-stock.show', $entry))->assertOk()->assertSee('قميص قطني')->assertSee('25.50')->assertDontSee('اسم جديد');
        $this->get(route('inventory.opening-stock.index'))->assertOk()->assertSee($entry->number)->assertSee('opening-stock.js');
        $this->get(route('inventory.index'))->assertOk()->assertSee('opening-stock-config')->assertSee('<td>الفرع الأول</td>', false);
    }

    public function test_batch_lookup_query_count_does_not_grow_with_the_number_of_rows(): void
    {
        $selectCount = function (array $rows): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->postJson(route('inventory.opening-stock.store'), $this->payload($rows))->assertCreated();
            $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'))->count();
            DB::disableQueryLog();

            return $queries;
        };
        $single = $selectCount([$this->row(['name' => 'واحد', 'code' => null])]);
        $rows = [];
        for ($index = 0; $index < 30; $index++) {
            $rows[] = $this->row(['name' => 'دفعة '.$index, 'code' => null]);
        }
        $batch = $selectCount($rows);
        $this->assertLessThanOrEqual($single + 2, $batch);
        $this->assertLessThanOrEqual(21, $batch);
    }

    public function test_catalog_options_quick_add_and_product_search_are_available(): void
    {
        $this->getJson(route('inventory.opening-stock.options'))->assertOk()->assertJsonPath('colors.0.id', $this->color->id);
        $this->postJson(route('inventory.opening-stock.options.store'), ['type' => 'color', 'name' => 'أبيض', 'code' => 'wht'])->assertCreated()->assertJsonPath('data.code', 'WHT');
        $this->postJson(route('inventory.opening-stock.options.store'), ['type' => 'color', 'name' => 'أبيض', 'code' => 'WHT'])->assertUnprocessable();
        $this->existingVariant();
        $this->getJson(route('inventory.opening-stock.products', ['q' => 'SHIRT']))->assertOk()->assertJsonPath('data.0.code', 'SHIRT-01')->assertJsonPath('data.0.variants.0.selling_price', '25.50');
    }

    public function test_one_image_is_shared_by_multiple_sizes_of_the_same_color(): void
    {
        $size = Size::create(['name' => 'XL', 'code' => 'XL']);
        $data = $this->payload([$this->row(), $this->row(['size_id' => $size->id])], ['image_rows' => [0]]);
        $this->withHeader('Accept', 'application/json')->post(route('inventory.opening-stock.store'), [
            'payload' => json_encode($data), 'images' => [0 => UploadedFile::fake()->image('shirt.png')],
        ])->assertCreated();
        $this->assertDatabaseCount('product_images', 1);
        $this->assertDatabaseCount('opening_stock_items', 2);
    }

    public function test_duplicate_color_images_and_replacement_of_existing_images_are_rejected(): void
    {
        $size = Size::create(['name' => 'XL', 'code' => 'XL']);
        $data = $this->payload([
            $this->row(['image' => UploadedFile::fake()->image('one.png')]),
            $this->row(['size_id' => $size->id, 'image' => UploadedFile::fake()->image('two.png')]),
        ]);
        $this->withHeader('Accept', 'application/json')->post(route('inventory.opening-stock.store'), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('rows.1.image');
        $variant = $this->existingVariant();
        ProductImage::create(['product_id' => $variant->product_id, 'color_id' => $this->color->id, 'image_path' => 'existing.png']);
        $this->withHeader('Accept', 'application/json')->post(route('inventory.opening-stock.store'), $this->payload([
            $this->row(['image' => UploadedFile::fake()->image('new.png')]),
        ]))->assertUnprocessable()->assertJsonValidationErrors('rows.0.image');
        $this->assertDatabaseCount('opening_stock_entries', 0);
    }

    public function test_rows_referencing_inactive_branches_or_unknown_attributes_are_rejected(): void
    {
        $this->postJson(route('inventory.opening-stock.store'), $this->payload([$this->row(['size_id' => 9999])]))
            ->assertUnprocessable()->assertJsonValidationErrors('rows.0.size_id');
        DB::table('branches')->where('id', $this->branchId)->update(['is_active' => false]);
        $this->postJson(route('inventory.opening-stock.store'), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    public function test_the_upper_bound_of_financial_totals_is_calculated_exactly(): void
    {
        $this->postJson(route('inventory.opening-stock.store'), $this->payload([
            $this->row(['qty' => 1000000, 'cost' => '9999999.99']),
        ]))->assertCreated()->assertJsonPath('total_cost', '9999999990000.00');
    }

    public function test_history_accepts_branch_and_date_filters_including_an_end_date_only(): void
    {
        $this->postJson(route('inventory.opening-stock.store'), $this->payload())->assertCreated();
        $url = route('inventory.opening-stock.index', ['branch_id' => $this->branchId, 'from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()]);
        $this->get($url)->assertOk()->assertSee('OS-000001');
        $this->get(route('inventory.opening-stock.index', ['to' => now()->toDateString()]))->assertOk()->assertSee('OS-000001');
    }

    public function test_malformed_multipart_payloads_fail_validation_without_writing(): void
    {
        foreach (['{broken', json_encode(['rows' => 'invalid']), json_encode($this->payload(null, ['image_rows' => ['bad']]))] as $payload) {
            $this->withHeader('Accept', 'application/json')->post(route('inventory.opening-stock.store'), ['payload' => $payload])->assertUnprocessable();
        }
        $this->assertDatabaseCount('opening_stock_entries', 0);
    }
}
