<?php

namespace Tests\Feature\Catalog;

use App\Actions\Purchases\SavePurchaseProductAction;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Size;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ProductColorImagesTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Color $black;

    private Color $white;

    private Size $small;

    private Size $medium;

    private Size $large;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->salesOwner()->create());
        Storage::fake('public');
        $this->category = Category::create(['name' => 'ملابس']);
        $this->black = Color::create(['name' => 'أسود', 'code' => 'BLK']);
        $this->white = Color::create(['name' => 'أبيض', 'code' => 'WHT']);
        $this->small = Size::create(['name' => 'S', 'code' => 'S']);
        $this->medium = Size::create(['name' => 'M', 'code' => 'M']);
        $this->large = Size::create(['name' => 'L', 'code' => 'L']);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['name' => 'قميص', 'code' => 'SHIRT', 'category_id' => $this->category->id, 'colors' => [$this->black->id, $this->white->id],
            'sizes' => [$this->small->id, $this->medium->id, $this->large->id], 'selling_price' => '25.00'], $changes);
    }

    private function createProduct(array $changes = []): Product
    {
        $this->postJson(route('purchases.products.store'), $this->payload($changes))->assertCreated();

        return Product::latest('id')->firstOrFail();
    }

    public function test_two_color_images_are_shared_by_six_variants_and_store_two_pairs(): void
    {
        $payload = $this->payload(['images' => [
            ['color_id' => $this->black->id, 'image' => UploadedFile::fake()->image('black.jpg')],
            ['color_id' => $this->white->id, 'image' => UploadedFile::fake()->image('white.png')],
        ]]);
        $response = $this->withHeader('Accept', 'application/json')->post(route('purchases.products.store'), $payload)->assertCreated()->assertJsonCount(6, 'data');
        $this->assertDatabaseCount('product_variants', 6);
        $this->assertDatabaseCount('product_images', 2);
        $this->assertCount(4, Storage::disk('public')->allFiles('products/colors'));
        foreach ([$this->black->id, $this->white->id] as $colorId) {
            $rows = collect($response->json('data'))->where('color_id', $colorId);
            $this->assertCount(3, $rows);
            $this->assertCount(1, $rows->pluck('image_url')->unique());
            $this->assertNotNull($rows->first()['image_url']);
        }
        $this->assertDatabaseCount('inventories', 0);
        $this->assertDatabaseCount('purchase_invoices', 0);
    }

    public function test_adding_sizes_reuses_the_products_existing_color_image(): void
    {
        $product = $this->createProduct(['colors' => [$this->black->id], 'sizes' => [$this->small->id]]);
        $this->withHeader('Accept', 'application/json')->post(route('catalog.product-images.store', $product), [
            'images' => [['color_id' => $this->black->id, 'image' => UploadedFile::fake()->image('black.jpg')]],
        ])->assertOk();
        $original = ProductImage::firstOrFail();
        $response = $this->postJson(route('purchases.products.store'), ['product_id' => $product->id, 'colors' => [$this->black->id],
            'sizes' => [$this->medium->id, $this->large->id], 'selling_price' => '30.00'])->assertCreated();
        $this->assertDatabaseCount('product_images', 1);
        $this->assertSame($original->url, $response->json('data.0.image_url'));
        $this->assertSame($original->url, $response->json('data.1.image_url'));
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_different_products_can_have_separate_images_for_the_same_color(): void
    {
        $first = $this->createProduct();
        $second = $this->createProduct(['name' => 'بنطال', 'code' => 'PANTS']);
        foreach ([$first, $second] as $product) {
            $this->withHeader('Accept', 'application/json')->post(route('catalog.product-images.store', $product), [
                'images' => [['color_id' => $this->black->id, 'image' => UploadedFile::fake()->image('black.jpg')]],
            ])->assertOk();
        }
        $this->assertDatabaseCount('product_images', 2);
        $this->assertCount(4, Storage::disk('public')->allFiles());
    }

    public function test_duplicate_colors_or_replacement_uploads_are_rejected_without_overwriting_files(): void
    {
        $product = $this->createProduct();
        $this->withHeader('Accept', 'application/json')->post(route('catalog.product-images.store', $product), [
            'images' => [['color_id' => $this->black->id, 'image' => UploadedFile::fake()->image('one.jpg')], ['color_id' => $this->black->id, 'image' => UploadedFile::fake()->image('two.jpg')]],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('product_images', 0);
        foreach (['one.jpg', 'two.jpg'] as $index => $name) {
            $response = $this->withHeader('Accept', 'application/json')->post(route('catalog.product-images.store', $product), [
                'images' => [['color_id' => $this->black->id, 'image' => UploadedFile::fake()->image($name)]],
            ]);
            if ($index === 0) {
                $response->assertOk();
            } else {
                $response->assertUnprocessable();
            }
        }
        $this->assertDatabaseCount('product_images', 1);
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_images_cannot_be_attached_to_unselected_or_unrelated_colors(): void
    {
        $this->withHeader('Accept', 'application/json')->post(route('purchases.products.store'), $this->payload(['colors' => [$this->black->id], 'images' => [
            ['color_id' => $this->white->id, 'image' => UploadedFile::fake()->image('wrong.jpg')],
        ]]))->assertUnprocessable();
        $this->assertDatabaseCount('products', 0);
        $product = $this->createProduct(['colors' => [$this->black->id]]);
        $this->withHeader('Accept', 'application/json')->post(route('catalog.product-images.store', $product), [
            'images' => [['color_id' => $this->white->id, 'image' => UploadedFile::fake()->image('wrong.jpg')]],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('product_images', 0);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_invalid_missing_oversized_files_and_client_file_paths_are_rejected(): void
    {
        $product = $this->createProduct();
        foreach ([['color_id' => $this->black->id], ['color_id' => $this->black->id, 'image' => '../../.env'],
            ['color_id' => $this->black->id, 'image' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php')],
            ['color_id' => $this->black->id, 'image' => UploadedFile::fake()->image('large.jpg')->size(4097)],
        ] as $upload) {
            $this->withHeader('Accept', 'application/json')->post(route('catalog.product-images.store', $product), ['images' => [$upload]])->assertUnprocessable();
        }
        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_uploaded_names_cannot_choose_the_storage_path_or_executable_extension(): void
    {
        $product = $this->createProduct();
        $file = UploadedFile::fake()->image('valid.jpg');
        $upload = new UploadedFile($file->getPathname(), '../../outside.jpg', 'image/jpeg', null, true);
        $this->withHeader('Accept', 'application/json')->post(route('catalog.product-images.store', $product), [
            'images' => [['color_id' => $this->black->id, 'image' => $upload]],
        ])->assertOk();
        $image = ProductImage::firstOrFail();
        $this->assertMatchesRegularExpression('#\Aproducts/colors/[a-f0-9-]{36}/optimized\.webp\z#', $image->image_path);
        Storage::disk('public')->assertExists($image->image_path);
    }

    public function test_partial_image_failure_rolls_back_new_products_variants_images_and_files(): void
    {
        $number = 0;
        ProductImage::creating(function () use (&$number): void {
            if (++$number === 2) {
                throw new RuntimeException('second image failed');
            }
        });
        try {
            app(SavePurchaseProductAction::class)->execute($this->payload(['images' => [
                ['color_id' => $this->black->id, 'image' => UploadedFile::fake()->image('black.jpg')],
                ['color_id' => $this->white->id, 'image' => UploadedFile::fake()->image('white.jpg')],
            ]]));
            $this->fail('Expected image failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('second image failed', $exception->getMessage());
        } finally {
            ProductImage::flushEventListeners();
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_variants', 0);
        $this->assertDatabaseCount('product_images', 0);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_purchase_search_and_opening_lookup_return_shared_images_without_per_row_queries(): void
    {
        $product = $this->createProduct();
        $this->withHeader('Accept', 'application/json')->post(route('catalog.product-images.store', $product), [
            'images' => [['color_id' => $this->black->id, 'image' => UploadedFile::fake()->image('black.jpg')]],
        ])->assertOk();
        $url = ProductImage::firstOrFail()->url;
        $this->getJson(route('purchases.products.index', ['q' => 'SHIRT']))->assertOk()->assertJsonPath('data.0.image_url', $url)->assertJsonPath('data.1.image_url', $url);
        $row = ['name' => 'قميص', 'code' => null, 'category_id' => $this->category->id, 'color_id' => $this->black->id];
        $count = function (int $rows) use ($row, $product, $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->postJson(route('inventory.opening-stock.images.resolve'), ['rows' => array_fill(0, $rows, $row)])
                ->assertOk()->assertJsonPath('data.0.product_id', $product->id)->assertJsonPath('data.0.image_url', $url);
            $count = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'))->count();
            DB::disableQueryLog();

            return $count;
        };
        $this->assertLessThanOrEqual($count(1) + 1, $count(20));
    }

    public function test_ambiguous_product_names_require_the_explicit_product_code(): void
    {
        $this->createProduct();
        $this->createProduct(['code' => 'ANOTHER']);
        $row = ['name' => 'قميص', 'category_id' => $this->category->id, 'color_id' => $this->black->id];
        $this->postJson(route('inventory.opening-stock.images.resolve'), ['rows' => [$row]])->assertOk()->assertJsonPath('data.0.product_id', null);
        $this->postJson(route('inventory.opening-stock.images.resolve'), ['rows' => [$row + ['code' => 'SHIRT']]])->assertOk()->assertJsonPath('data.0.product_id', Product::where('code', 'SHIRT')->firstOrFail()->id);
    }

    public function test_image_routes_require_authentication_and_have_no_stock_or_financial_effects(): void
    {
        $product = $this->createProduct();
        auth()->logout();
        $this->getJson(route('catalog.product-images.index', $product))->assertUnauthorized();
        $this->postJson(route('catalog.product-images.store', $product))->assertUnauthorized();
        $this->postJson(route('inventory.opening-stock.images.resolve'), ['rows' => []])->assertUnauthorized();
        $this->assertDatabaseCount('inventories', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('supplier_payments', 0);
    }
}
