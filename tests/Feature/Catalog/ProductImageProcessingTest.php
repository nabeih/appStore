<?php

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\MigrateProductImageAction;
use App\Actions\Catalog\ProcessProductImageAction;
use App\Actions\Catalog\UpdateProductAction;
use App\Actions\Purchases\SavePurchaseProductAction;
use App\Models\Category;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\SaleInvoice;
use App\Models\SaleItem;
use App\Models\Size;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ProductImageProcessingTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Color $color;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->salesOwner()->create());
        Storage::fake('public');
        $category = Category::create(['name' => 'ملابس']);
        $this->color = Color::create(['name' => 'أسود', 'code' => 'BLK']);
        $sizes = collect(['S', 'M', 'L'])->map(fn ($name) => Size::create(['name' => $name, 'code' => $name]));
        app(SavePurchaseProductAction::class)->execute(['name' => 'قميص صور', 'code' => 'PHOTO', 'category_id' => $category->id,
            'colors' => [$this->color->id], 'sizes' => $sizes->pluck('id')->all(), 'selling_price' => '35']);
        $this->product = Product::firstOrFail();
    }

    private function upload(UploadedFile $file): ProductImage
    {
        $this->post(route('catalog.product-images.store', $this->product), [
            'images' => [['color_id' => $this->color->id, 'image' => $file]],
        ], ['Accept' => 'application/json'])->assertOk();

        return ProductImage::firstOrFail();
    }

    private function assertPair(ProductImage $image, array $optimized, array $thumbnail): void
    {
        foreach ([$image->image_path => $optimized, $image->thumbnail_path => $thumbnail] as $path => $dimensions) {
            $info = getimagesize(Storage::disk('public')->path($path));
            $this->assertSame($dimensions, [$info[0], $info[1]]);
            $this->assertSame('image/webp', $info['mime']);
        }
    }

    private function historicalItem(string $path): SaleItem
    {
        return SaleItem::create(['sale_invoice_id' => SaleInvoice::factory()->create()->id,
            'product_variant_id' => $this->product->variants()->firstOrFail()->id, 'product_name' => 'اسم تاريخي',
            'product_code' => 'PHOTO', 'color_name' => 'أسود', 'size_name' => 'S', 'sku' => 'PHOTO-BLK-S',
            'image_path' => $path, 'quantity' => 1, 'base_price_minor' => 3500, 'unit_price_minor' => 3500,
            'gross_minor' => 3500, 'line_discount_minor' => 0, 'invoice_discount_minor' => 0,
            'net_minor' => 3500, 'unit_cost' => '20', 'cost_minor' => 2000]);
    }

    public function test_real_upload_stores_only_two_webp_files_with_correct_bounds_and_aspect_ratio(): void
    {
        $image = $this->upload(UploadedFile::fake()->image('portrait.jpg', 1800, 2400));
        $this->assertPair($image, [900, 1200], [120, 160]);
        $this->assertCount(2, Storage::disk('public')->allFiles());
        $this->assertSame(3, $this->product->variants()->count());
        $this->assertDatabaseCount('product_images', 1);
    }

    public function test_small_transparent_png_is_not_upscaled_and_preserves_transparency(): void
    {
        $source = imagecreatetruecolor(40, 20);
        imagealphablending($source, false);
        imagesavealpha($source, true);
        imagefill($source, 0, 0, imagecolorallocatealpha($source, 0, 0, 0, 127));
        ob_start();
        imagepng($source);
        $bytes = ob_get_clean();
        $image = $this->upload(UploadedFile::fake()->createWithContent('alpha.png', $bytes));
        $this->assertPair($image, [40, 20], [40, 20]);
        $decoded = imagecreatefromwebp(Storage::disk('public')->path($image->image_path));
        $this->assertGreaterThan(120, imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0))['alpha']);
    }

    public function test_jpeg_exif_orientation_is_applied_before_resizing(): void
    {
        $file = UploadedFile::fake()->image('rotated.jpg', 120, 60);
        $jpeg = file_get_contents($file->getPathname());
        $tiff = 'II'.pack('v', 42).pack('V', 8).pack('v', 1).pack('vvVv', 0x0112, 3, 1, 6).pack('vV', 0, 0);
        $exif = "Exif\0\0".$tiff;
        $bytes = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        $image = $this->upload(UploadedFile::fake()->createWithContent('rotated.jpg', $bytes));
        $this->assertPair($image, [60, 120], [60, 120]);
    }

    public function test_replacement_retires_the_unused_pair_only_after_successful_save(): void
    {
        $old = $this->upload(UploadedFile::fake()->image('one.jpg', 500, 400));
        $oldPaths = [$old->image_path, $old->thumbnail_path];
        $this->post('/api/products/'.$this->product->id, ['_method' => 'PUT', 'image_color_id' => $this->color->id,
            'image' => UploadedFile::fake()->image('two.png', 600, 300)], ['Accept' => 'application/json'])->assertOk();
        $new = $old->fresh();
        $this->assertNotSame($oldPaths[0], $new->image_path);
        $this->assertPair($new, [600, 300], [160, 80]);
        Storage::disk('public')->assertMissing($oldPaths);
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_historical_sales_keep_the_old_image_pair_when_a_product_image_is_replaced(): void
    {
        $old = $this->upload(UploadedFile::fake()->image('old.jpg'));
        $item = $this->historicalItem($old->image_path);
        app(UpdateProductAction::class)->execute($this->product, ['image_color_id' => $this->color->id, 'image' => UploadedFile::fake()->image('new.jpg')]);
        $this->assertSame($old->image_path, $item->fresh()->image_path);
        Storage::disk('public')->assertExists([$old->image_path, $old->thumbnail_path]);
        $this->assertCount(4, Storage::disk('public')->allFiles());
    }

    public function test_failed_replacement_keeps_original_database_and_files_and_removes_new_pair(): void
    {
        $old = $this->upload(UploadedFile::fake()->image('old.jpg'));
        ProductImage::updating(function (): void {
            throw new RuntimeException('image update failed');
        });
        try {
            app(UpdateProductAction::class)->execute($this->product, ['image_color_id' => $this->color->id, 'image' => UploadedFile::fake()->image('new.jpg')]);
            $this->fail('Expected update failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('image update failed', $error->getMessage());
        } finally {
            ProductImage::flushEventListeners();
        }
        $this->assertSame($old->image_path, $old->fresh()->image_path);
        Storage::disk('public')->assertExists([$old->image_path, $old->thumbnail_path]);
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_existing_images_command_relinks_history_preserves_financial_values_and_is_restart_safe(): void
    {
        $file = UploadedFile::fake()->image('legacy.jpg', 2000, 1000);
        Storage::disk('public')->put('products/legacy.jpg', file_get_contents($file->getPathname()));
        $image = ProductImage::create(['product_id' => $this->product->id, 'color_id' => $this->color->id, 'image_path' => 'products/legacy.jpg']);
        $item = $this->historicalItem($image->image_path);
        $snapshot = $item->only(['product_name', 'unit_price_minor', 'cost_minor']);
        $this->artisan('app:images-optimize', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('products/legacy.jpg', $image->fresh()->image_path);
        $this->assertCount(1, Storage::disk('public')->allFiles());
        $this->artisan('app:images-optimize', ['--chunk' => 1, '--delete-originals' => true])->assertSuccessful();
        $image->refresh();
        $this->assertPair($image, [1200, 600], [160, 80]);
        $this->assertSame($image->image_path, $item->fresh()->image_path);
        $this->assertSame($snapshot, $item->fresh()->only(array_keys($snapshot)));
        Storage::disk('public')->assertMissing('products/legacy.jpg');
        $this->artisan('app:images-optimize')->assertSuccessful();
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_command_reports_missing_files_but_continues_and_can_keep_originals(): void
    {
        $file = UploadedFile::fake()->image('legacy.png');
        Storage::disk('public')->put('legacy.png', file_get_contents($file->getPathname()));
        ProductImage::create(['product_id' => $this->product->id, 'color_id' => $this->color->id, 'image_path' => 'missing.png']);
        DB::table('products')->where('id', $this->product->id)->update(['image_path' => 'legacy.png']);
        $this->artisan('app:images-optimize', ['--keep-originals' => true])->assertFailed();
        Storage::disk('public')->assertExists('legacy.png');
        $newPath = $this->product->fresh()->image_path;
        $this->assertStringEndsWith('/optimized.webp', $newPath);
        Storage::disk('public')->assertExists([$newPath, MigrateProductImageAction::thumbnailPath($newPath)]);
    }

    public function test_command_cleans_new_files_if_database_conversion_fails(): void
    {
        $file = UploadedFile::fake()->image('legacy.jpg');
        Storage::disk('public')->put('legacy.jpg', file_get_contents($file->getPathname()));
        $image = ProductImage::create(['product_id' => $this->product->id, 'color_id' => $this->color->id, 'image_path' => 'legacy.jpg']);
        ProductImage::updating(function (): void {
            throw new RuntimeException('conversion write failed');
        });
        try {
            $this->artisan('app:images-optimize')->assertFailed();
        } finally {
            ProductImage::flushEventListeners();
        }
        $this->assertSame('legacy.jpg', $image->fresh()->image_path);
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_unsafe_paths_and_non_image_uploads_are_rejected_without_reading_external_files(): void
    {
        foreach (['../secret.jpg', '/etc/photo.png', 'https://example.com/image.jpg', 'products/../photo.webp', 'C:\\photo.jpg', 'products/%2e%2e/photo.png'] as $path) {
            try {
                app(ProcessProductImageAction::class)->existing($path);
                $this->fail('Unsafe path accepted.');
            } catch (ValidationException) {
                $this->assertCount(0, Storage::disk('public')->allFiles());
            }
        }
        $this->post(route('catalog.product-images.store', $this->product), ['images' => [
            ['color_id' => $this->color->id, 'image' => UploadedFile::fake()->createWithContent('bad.jpg', '<?php echo 1;')],
        ]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_inventory_cashier_and_api_share_color_thumbnails_and_link_to_optimized_images(): void
    {
        $image = $this->upload(UploadedFile::fake()->image('public.jpg', 1800, 1200));
        $branch = DB::table('branches')->insertGetId(['name' => 'الرئيسي']);
        foreach ($this->product->variants as $variant) {
            Inventory::create(['branch_id' => $branch, 'product_variant_id' => $variant->id, 'quantity' => 2, 'average_cost' => '20', 'value_minor' => 4000]);
        }
        $this->get(route('inventory.index'))->assertOk()->assertSee($image->thumbnail_url, false);
        $catalog = $this->getJson(route('sales.catalog', ['branch_id' => $branch]))->assertOk();
        $this->assertCount(1, collect($catalog->json('data.0.variants'))->pluck('image_url')->unique());
        $catalog->assertJsonPath('data.0.variants.0.image_url', $image->thumbnail_url)
            ->assertJsonPath('data.0.variants.0.optimized_image_url', $image->optimized_url);
        $this->getJson('/api/products/'.$this->product->id)->assertOk()->assertJsonPath('images.0.thumbnail_url', $image->thumbnail_url);

    }

    public function test_second_file_write_failure_removes_the_first_file_and_creates_no_image_row(): void
    {
        $disk = Storage::disk('public');
        $proxy = \Mockery::mock($disk);
        $proxy->shouldReceive('put')->twice()->andReturnUsing(function (string $path, string $bytes) use ($disk): bool {
            return str_ends_with($path, '/thumbnail.webp') ? false : $disk->put($path, $bytes);
        });
        $proxy->shouldReceive('delete')->once()->andReturnUsing(fn (array $paths): bool => $disk->delete($paths));
        Storage::set('public', $proxy);
        try {
            app(ProcessProductImageAction::class)->upload(UploadedFile::fake()->image('failure.jpg'));
            $this->fail('Expected storage failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('تعذر حفظ نسختي الصورة.', $error->getMessage());
        }
        $this->assertCount(0, $disk->allFiles());
        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_product_api_query_count_does_not_grow_with_product_and_size_count(): void
    {
        $this->upload(UploadedFile::fake()->image('one.jpg'));
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/products')->assertOk();
            $count = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'))->count();
            DB::disableQueryLog();

            return $count;
        };
        $singleCount = $count();
        $variant = $this->product->variants()->firstOrFail();
        for ($i = 0; $i < 12; $i++) {
            $product = Product::create(['name' => 'منتج '.$i, 'code' => 'COUNT-'.$i, 'category_id' => $this->product->category_id, 'is_active' => true]);
            foreach ($this->product->variants as $original) {
                $product->variants()->create(['color_id' => $original->color_id, 'size_id' => $original->size_id, 'sku' => 'COUNT-'.$i.'-'.$original->size_id, 'selling_price' => '35', 'is_active' => true]);
            }
        }
        $this->assertLessThanOrEqual($singleCount + 1, $count());
    }

    public function test_command_repairs_a_missing_thumbnail_without_creating_extra_copies(): void
    {
        $image = $this->upload(UploadedFile::fake()->image('repair.jpg', 1800, 900));
        $oldPath = $image->image_path;
        Storage::disk('public')->delete($image->thumbnail_path);
        $this->artisan('app:images-optimize', ['--dry-run' => true])->expectsOutputToContain('"requires_processing":true')->assertSuccessful();
        $this->assertSame($oldPath, $image->fresh()->image_path);
        $this->artisan('app:images-optimize', ['--delete-originals' => true])->assertSuccessful();
        $image->refresh();
        $this->assertPair($image, [1200, 600], [160, 80]);
        $this->assertCount(2, Storage::disk('public')->allFiles());
        $this->artisan('app:images-optimize')->assertSuccessful();
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_product_creation_api_processes_uploads_and_rejects_client_supplied_paths(): void
    {
        $payload = ['category_id' => $this->product->category_id, 'code' => 'API-IMAGE', 'name' => 'منتج API',
            'variants' => [['color_id' => $this->color->id, 'size_id' => $this->product->variants()->firstOrFail()->size_id, 'selling_price' => '35']],
            'images' => [['color_id' => $this->color->id, 'image_path' => '../../outside.jpg']]];
        $this->postJson('/api/products', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('products', 1);
        $payload['images'] = [['color_id' => $this->color->id, 'image' => UploadedFile::fake()->image('api.png', 1600, 800)]];
        $this->post('/api/products', $payload, ['Accept' => 'application/json'])->assertCreated();
        $image = ProductImage::firstOrFail();
        $this->assertPair($image, [1200, 600], [160, 80]);
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_history_only_shared_legacy_file_is_processed_once_without_false_missing_file_failures(): void
    {
        $file = UploadedFile::fake()->image('historical.jpg', 1800, 900);
        Storage::disk('public')->put('historical.jpg', file_get_contents($file->getPathname()));
        $first = $this->historicalItem('historical.jpg');
        $second = $this->historicalItem('historical.jpg');
        $this->artisan('app:images-optimize', ['--chunk' => 50, '--delete-originals' => true])->assertSuccessful();
        $this->assertSame($first->fresh()->image_path, $second->fresh()->image_path);
        $this->assertStringEndsWith('/optimized.webp', $first->fresh()->image_path);
        Storage::disk('public')->assertMissing('historical.jpg');
        $this->assertCount(2, Storage::disk('public')->allFiles());
        $this->assertSame(2000, (int) $first->fresh()->cost_minor);
        $this->assertSame(3500, (int) $second->fresh()->unit_price_minor);
        $this->artisan('app:images-optimize')->assertSuccessful();
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_actual_processing_reduces_transfer_sizes_and_reading_urls_performs_no_extra_queries(): void
    {
        $source = imagecreatetruecolor(2400, 1600);
        for ($y = 0; $y < 1600; $y += 10) {
            for ($x = 0; $x < 2400; $x += 10) {
                $color = imagecolorallocate($source, ($x * 7 + $y) % 256, ($y * 13 + $x) % 256, ($x * 3 + $y * 5) % 256);
                imagefilledrectangle($source, $x, $y, $x + 9, $y + 9, $color);
            }
        }
        ob_start();
        imagejpeg($source, null, 95);
        $bytes = ob_get_clean();
        $started = hrtime(true);
        $image = $this->upload(UploadedFile::fake()->createWithContent('benchmark.jpg', $bytes));
        $milliseconds = (hrtime(true) - $started) / 1_000_000;
        $disk = Storage::disk('public');
        $originalBytes = strlen($bytes);
        $optimizedBytes = $disk->size($image->image_path);
        $thumbnailBytes = $disk->size($image->thumbnail_path);
        $this->assertLessThan($originalBytes, $optimizedBytes);
        $this->assertLessThan($optimizedBytes, $thumbnailBytes);
        DB::flushQueryLog();
        DB::enableQueryLog();
        for ($i = 0; $i < 100; $i++) {
            $image->thumbnail_url;
            $image->optimized_url;
        }
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
        file_put_contents(storage_path('app/reports/image-processing-benchmark-2026-10-26.json'), json_encode([
            'fixture' => 'synthetic 2400x1600 JPEG, quality 95', 'php' => PHP_VERSION,
            'source_bytes' => $originalBytes, 'optimized_bytes' => $optimizedBytes, 'thumbnail_bytes' => $thumbnailBytes,
            'http_upload_and_processing_ms' => round($milliseconds, 2), 'extra_queries_for_100_url_reads' => 0,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
