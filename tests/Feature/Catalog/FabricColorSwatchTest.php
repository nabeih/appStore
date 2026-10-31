<?php

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\ManageFabricSwatchAction;
use App\Actions\Catalog\ProcessProductImageAction;
use App\Actions\Catalog\UpdateProductAction;
use App\Actions\Purchases\SavePurchaseProductAction;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductColorSwatch;
use App\Models\ProductImage;
use App\Models\Size;
use App\Models\User;
use App\Support\Sales\SalesAccess;
use Database\Factories\BranchFactory;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class FabricColorSwatchTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    private Color $color;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') === 'mysql' && getenv('DB_DATABASE') !== 'appstore_fabric_swatch_test') {
            throw new \LogicException('Fabric tests require the explicitly approved isolated MySQL database.');
        }
        parent::setUp();
        $this->owner = User::factory()->salesOwner()->create();
        $this->actingAs($this->owner);
        Storage::fake('public');
        $category = Category::create(['name' => 'ملابس']);
        $this->color = Color::create(['name' => 'أسود', 'code' => 'BLK', 'hex_code' => '#123456']);
        foreach (['S', 'M', 'L'] as $name) {
            Size::create(['name' => $name, 'code' => $name]);
        }
        $this->product = $this->makeProduct('FABRIC', $category->id);
    }

    private function makeProduct(string $code, ?int $categoryId = null): Product
    {
        app(SavePurchaseProductAction::class)->execute(['name' => 'قميص '.$code, 'code' => $code,
            'category_id' => $categoryId ?? $this->product->category_id, 'colors' => [$this->color->id],
            'sizes' => Size::pluck('id')->all(), 'selling_price' => '35']);

        return Product::where('code', $code)->firstOrFail();
    }

    private function url(?Product $product = null, ?int $colorId = null): string
    {
        return route('catalog.swatches.store', [$product ?? $this->product, $colorId ?? $this->color->id]);
    }

    private function data(?string $revision = null): array
    {
        return ['revision' => $revision, 'mode' => 'upload', 'image' => UploadedFile::fake()->image('fabric.jpg', 500, 400),
            'x' => 30, 'y' => 20, 'size' => 250];
    }

    private function upload(?Product $product = null): ProductColorSwatch
    {
        $product ??= $this->product;
        $this->post($this->url($product), $this->data(), ['Accept' => 'application/json'])->assertOk();

        return ProductColorSwatch::where('product_id', $product->id)->firstOrFail();
    }

    private function source(?Product $product = null, ?int $colorId = null): ProductImage
    {
        $product ??= $this->product;
        $file = UploadedFile::fake()->image('source.jpg', 600, 400);
        $paths = app(ProcessProductImageAction::class)->upload($file);

        return ProductImage::create(['product_id' => $product->id, 'color_id' => $colorId ?? $this->color->id] + $paths);
    }

    private function cropData(ProductImage $source): array
    {
        return ['revision' => null, 'mode' => 'crop', 'source_image_id' => $source->id,
            'source_revision' => ManageFabricSwatchAction::sourceRevision($source), 'x' => 100, 'y' => 50, 'size' => 200];
    }

    public function test_upload_creates_one_real_square_webp_shared_by_all_sizes_without_changing_main_images(): void
    {
        $source = $this->source();
        $hash = hash_file('sha256', Storage::disk('public')->path($source->image_path));
        $swatch = $this->upload();
        $info = getimagesize(Storage::disk('public')->path($swatch->image_path));
        $this->assertSame([128, 128, 'image/webp'], [$info[0], $info[1], $info['mime']]);
        $this->assertSame(3, $this->product->variants()->count());
        $this->assertDatabaseCount('product_color_swatches', 1);
        $this->assertDatabaseCount('product_images', 1);
        $this->assertSame($hash, hash_file('sha256', Storage::disk('public')->path($source->image_path)));
        $this->assertSame(0, Inventory::count());
        $this->assertDatabaseCount('stock_movements', 0);
        $this->getJson('/api/products/'.$this->product->id)->assertOk()->assertJsonPath('swatches.0.url', $swatch->url);
    }

    public function test_crop_uses_actual_selected_pixels_and_never_modifies_the_product_source(): void
    {
        $source = imagecreatetruecolor(600, 400);
        imagefilledrectangle($source, 0, 0, 299, 399, imagecolorallocate($source, 240, 5, 5));
        imagefilledrectangle($source, 300, 0, 599, 399, imagecolorallocate($source, 5, 5, 240));
        ob_start();
        imagepng($source);
        $bytes = ob_get_clean();
        Storage::disk('public')->put('source.png', $bytes);
        $image = ProductImage::create(['product_id' => $this->product->id, 'color_id' => $this->color->id, 'image_path' => 'source.png']);
        $data = $this->cropData($image);
        $data['x'] = 350;
        $data['y'] = 100;
        $this->postJson($this->url(), $data)->assertOk();
        $sample = ProductColorSwatch::firstOrFail();
        $decoded = imagecreatefromwebp(Storage::disk('public')->path($sample->image_path));
        $pixel = imagecolorsforindex($decoded, imagecolorat($decoded, 64, 64));
        $this->assertGreaterThan(200, $pixel['blue']);
        $this->assertLessThan(30, $pixel['red']);
        $this->assertSame($bytes, Storage::disk('public')->get('source.png'));
    }

    public function test_invalid_oversized_and_non_image_uploads_leave_no_files_or_records(): void
    {
        foreach ([
            UploadedFile::fake()->createWithContent('bad.jpg', '<?php echo 1;'),
            UploadedFile::fake()->image('large.jpg')->size(4097),
            UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml'),
        ] as $file) {
            $data = $this->data();
            $data['image'] = $file;
            $this->post($this->url(), $data, ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('product_color_swatches', 0);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_out_of_bounds_negative_fractional_and_oversized_crop_coordinates_are_rejected(): void
    {
        $source = $this->source();
        foreach ([['x' => 500], ['y' => 350], ['size' => 0], ['size' => 601], ['x' => -1], ['x' => '1.5'], ['size' => 16000001]] as $change) {
            $this->postJson($this->url(), array_replace($this->cropData($source), $change))->assertUnprocessable();
        }
        $this->assertDatabaseCount('product_color_swatches', 0);
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_crop_cannot_use_another_product_or_color_and_changed_sources_require_new_preview(): void
    {
        $other = $this->makeProduct('OTHER');
        $this->postJson($this->url(), $this->cropData($this->source($other)))->assertUnprocessable();
        $white = Color::create(['name' => 'أبيض', 'code' => 'WHT']);
        $this->postJson($this->url(), $this->cropData($this->source($this->product, $white->id)))->assertUnprocessable();
        $source = $this->source();
        $data = $this->cropData($source);
        app(UpdateProductAction::class)->execute($this->product, ['image_color_id' => $this->color->id, 'image' => UploadedFile::fake()->image('replacement.jpg', 600, 400)]);
        $this->postJson($this->url(), $data)->assertConflict();
        $this->assertDatabaseCount('product_color_swatches', 0);
    }

    public function test_same_color_on_different_products_has_independent_swatches(): void
    {
        $first = $this->upload();
        $second = $this->upload($this->makeProduct('SECOND'));
        $this->assertNotSame($first->image_path, $second->image_path);
        $this->assertSame($first->color_id, $second->color_id);
        $this->assertDatabaseCount('product_color_swatches', 2);
    }

    public function test_duplicate_create_and_stale_replacement_do_not_overwrite_an_existing_swatch(): void
    {
        $swatch = $this->upload();
        $this->post($this->url(), $this->data(), ['Accept' => 'application/json'])->assertConflict();
        $this->assertDatabaseCount('product_color_swatches', 1);
        $this->assertCount(1, Storage::disk('public')->allFiles());
        $this->post($this->url(), $this->data($swatch->revision), ['Accept' => 'application/json'])->assertOk();
        $current = $swatch->fresh();
        $this->assertNotSame($swatch->image_path, $current->image_path);
        Storage::disk('public')->assertMissing($swatch->image_path);
        $this->post($this->url(), $this->data($swatch->revision), ['Accept' => 'application/json'])->assertConflict();
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_database_unique_constraint_enforces_product_color_pair(): void
    {
        $swatch = $this->upload();
        try {
            ProductColorSwatch::factory()->create(['product_id' => $swatch->product_id, 'color_id' => $swatch->color_id]);
            $this->fail('Expected unique constraint violation.');
        } catch (QueryException) {
            $this->assertDatabaseCount('product_color_swatches', 1);
        }
    }

    public function test_failed_replacement_keeps_the_old_sample_and_cleans_the_new_file(): void
    {
        $swatch = $this->upload();
        ProductColorSwatch::saving(function (): void {
            throw new RuntimeException('sample failure');
        });
        try {
            app(ManageFabricSwatchAction::class)->save($this->product, $this->color->id, $this->data($swatch->revision), $this->owner);
            $this->fail('Expected sample failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('sample failure', $error->getMessage());
        } finally {
            ProductColorSwatch::flushEventListeners();
        }
        $this->assertSame($swatch->image_path, $swatch->fresh()->image_path);
        Storage::disk('public')->assertExists($swatch->image_path);
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_delete_restores_hex_fallback_without_deleting_main_product_photos(): void
    {
        $main = $this->source();
        $sample = $this->upload();
        $this->deleteJson($this->url(), ['revision' => $sample->revision])->assertOk()->assertJsonPath('colors.0.swatch', null)->assertJsonPath('colors.0.hex', '#123456');
        Storage::disk('public')->assertExists([$main->image_path, $main->thumbnail_path]);
        Storage::disk('public')->assertMissing($sample->image_path);
        $this->assertDatabaseCount('product_color_swatches', 0);
        $this->deleteJson($this->url(), ['revision' => $sample->revision])->assertOk();
    }

    public function test_a_shared_swatch_file_is_not_deleted_while_another_record_uses_it(): void
    {
        $sample = $this->upload();
        $other = $this->makeProduct('SHARED');
        ProductColorSwatch::factory()->create(['product_id' => $other->id, 'color_id' => $this->color->id, 'image_path' => $sample->image_path]);
        $this->deleteJson($this->url(), ['revision' => $sample->revision])->assertOk();
        Storage::disk('public')->assertExists($sample->image_path);
        $this->assertDatabaseCount('product_color_swatches', 1);
    }

    public function test_missing_hex_and_swatch_use_neutral_indicator_and_main_thumbnail_is_not_a_swatch(): void
    {
        $this->color->update(['name' => 'اسم درجة غير معروف مطلقًا', 'hex_code' => null]);
        $main = $this->source();
        $this->getJson(route('catalog.swatches.index', $this->product))->assertOk()->assertJsonPath('colors.0.swatch', null)
            ->assertJsonPath('colors.0.hex', null)->assertJsonPath('colors.0.source_image.url', $main->optimized_url);
        $html = Blade::render('<x-catalog.color-dot :hex="null" label="درجة غير معروفة" />');
        $this->assertStringContainsString('catalog-color-unknown', $html);
        $this->assertStringContainsString('درجة غير معروفة', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_authentication_permissions_and_product_color_membership_are_enforced(): void
    {
        $sample = $this->upload();
        $cashier = User::factory()->create(['role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $this->actingAs($cashier)->post($this->url(), $this->data($sample->revision), ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson($this->url(), ['revision' => $sample->revision])->assertForbidden();
        $this->get(route('catalog.swatches.edit', $this->product))->assertForbidden();
        $this->actingAs($this->owner);
        $this->post($this->url(null, 999999), $this->data(), ['Accept' => 'application/json'])->assertUnprocessable();
        $bad = $this->data($sample->revision);
        $bad['image_path'] = '../../outside.webp';
        $this->post($this->url(), $bad, ['Accept' => 'application/json'])->assertUnprocessable();
        auth()->logout();
        $this->post($this->url(), $this->data(), ['Accept' => 'application/json'])->assertUnauthorized();
        $this->assertSame($sample->image_path, $sample->fresh()->image_path);
    }

    public function test_failed_delete_audit_rolls_back_the_record_and_keeps_its_file(): void
    {
        $sample = $this->upload();
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'product.swatch_deleted') {
                throw new RuntimeException('audit failure');
            }
        });
        try {
            app(ManageFabricSwatchAction::class)->delete($this->product, $this->color->id, $sample->revision, $this->owner);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('audit failure', $error->getMessage());
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('product_color_swatches', 1);
        Storage::disk('public')->assertExists($sample->image_path);
    }

    public function test_management_inventory_and_cashier_expose_one_swatch_for_three_sizes_with_no_stock_effect(): void
    {
        $sample = $this->upload();
        $branch = BranchFactory::new()->create();
        foreach ($this->product->variants as $variant) {
            Inventory::create(['branch_id' => $branch->id, 'product_variant_id' => $variant->id, 'quantity' => 2, 'average_cost' => '20', 'value_minor' => 4000]);
        }
        $this->get(route('catalog.swatches.edit', $this->product))->assertOk()->assertSee('إدارة عينة القماش')->assertSee($sample->url, false);
        $this->get(route('inventory.index'))->assertOk()->assertSee($sample->url, false)->assertSee('إدارة عينة قماش هذا اللون');
        $catalog = $this->getJson(route('sales.catalog', ['branch_id' => $branch->id]))->assertOk();
        $this->assertSame([$sample->url], collect($catalog->json('data.0.variants'))->pluck('swatch_url')->unique()->values()->all());
        $this->getJson(route('purchases.products.index', ['q' => 'FABRIC']))->assertOk()->assertJsonPath('data.0.swatch_url', $sample->url);
        $this->postJson(route('inventory.opening-stock.images.resolve'), ['rows' => [['name' => $this->product->name, 'code' => $this->product->code, 'category_id' => $this->product->category_id, 'color_id' => $this->color->id]]])
            ->assertOk()->assertJsonPath('data.0.swatch_url', $sample->url);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_web_swatch_writes_require_a_valid_csrf_token(): void
    {
        $this->app->bind(PreventRequestForgery::class, function ($app) {
            return new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            };
        });
        $this->withSession(['_token' => 'swatch-test-csrf'])->post($this->url(), $this->data(), ['Accept' => 'application/json'])->assertStatus(419);
        $this->withSession(['_token' => 'swatch-test-csrf'])->post($this->url(), $this->data(), ['Accept' => 'application/json', 'X-CSRF-TOKEN' => 'swatch-test-csrf'])->assertOk();
        $this->assertDatabaseCount('product_color_swatches', 1);
    }

    public function test_swatch_eager_loading_keeps_product_list_query_count_bounded(): void
    {
        $this->upload();
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/products')->assertOk();
            $queries = collect(DB::getQueryLog())->filter(fn (array $row): bool => str_starts_with(strtolower($row['query']), 'select'))->count();
            DB::disableQueryLog();

            return $queries;
        };
        $single = $count();
        for ($i = 0; $i < 12; $i++) {
            $product = $this->makeProduct('QUERY-'.$i);
            ProductColorSwatch::factory()->create(['product_id' => $product->id, 'color_id' => $this->color->id]);
        }
        $this->assertLessThanOrEqual($single + 1, $count());
    }

    public function test_image_optimization_retains_originals_by_default_and_dry_run_never_changes_files(): void
    {
        $file = UploadedFile::fake()->image('legacy.jpg', 600, 400);
        Storage::disk('public')->put('legacy.jpg', file_get_contents($file->getPathname()));
        $main = ProductImage::create(['product_id' => $this->product->id, 'color_id' => $this->color->id, 'image_path' => 'legacy.jpg']);
        $before = Storage::disk('public')->get('legacy.jpg');
        $this->artisan('app:images-optimize', ['--dry-run' => true, '--delete-originals' => true])->assertSuccessful();
        $this->assertSame('legacy.jpg', $main->fresh()->image_path);
        $this->assertSame($before, Storage::disk('public')->get('legacy.jpg'));
        $this->assertCount(1, Storage::disk('public')->allFiles());
        $this->artisan('app:images-optimize')->assertSuccessful();
        Storage::disk('public')->assertExists('legacy.jpg');
        $this->assertCount(3, Storage::disk('public')->allFiles());
        $this->artisan('app:images-optimize', ['--keep-originals' => true, '--delete-originals' => true])->assertFailed();
    }

    public function test_explicit_original_deletion_respects_files_referenced_by_swatches_without_converting_swatches(): void
    {
        $file = UploadedFile::fake()->image('shared.jpg', 600, 400);
        Storage::disk('public')->put('shared.jpg', file_get_contents($file->getPathname()));
        ProductImage::create(['product_id' => $this->product->id, 'color_id' => $this->color->id, 'image_path' => 'shared.jpg']);
        $sample = ProductColorSwatch::factory()->create(['product_id' => $this->product->id, 'color_id' => $this->color->id, 'image_path' => 'shared.jpg']);
        $this->artisan('app:images-optimize', ['--delete-originals' => true])->assertSuccessful();
        Storage::disk('public')->assertExists('shared.jpg');
        $this->assertSame('shared.jpg', $sample->fresh()->image_path);
    }
}
