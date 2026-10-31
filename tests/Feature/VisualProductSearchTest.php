<?php

namespace Tests\Feature;

use App\Contracts\Search\ImageEmbeddingIndex;
use App\Contracts\Search\ImageEmbeddingProvider;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\Sales\SalesAccess;
use Database\Factories\BranchFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sales\SalesTestCase;

class VisualProductSearchTest extends SalesTestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') === 'mysql' && getenv('DB_DATABASE') !== 'appstore_fabric_swatch_test') {
            throw new \LogicException('Visual tests require the approved isolated database.');
        }
        parent::setUp();
    }

    public function test_visual_search_is_explicitly_unavailable_without_an_approved_provider(): void
    {
        $this->postJson(route('search.products.visual'), ['branch_id' => $this->branchId, 'image' => UploadedFile::fake()->image('garment.jpg')])->assertStatus(503);
        $this->assertFalse(app(ImageEmbeddingProvider::class)->available());
        $this->assertFalse(app(ImageEmbeddingIndex::class)->available());
    }

    public function test_visual_endpoint_requires_permissions_and_branch_scope_before_any_embedding(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson(route('search.products.visual'), [])->assertForbidden();
        $viewer = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('inventory_viewer')->id]);
        $other = BranchFactory::new()->create();
        $this->actingAs($viewer);
        $this->postJson(route('search.products.visual'), ['branch_id' => $other->id, 'image' => UploadedFile::fake()->image('garment.jpg')])->assertForbidden();
    }

    public function test_visual_upload_validation_rejects_oversized_svg_and_non_images(): void
    {
        foreach ([UploadedFile::fake()->create('unsafe.svg', 2, 'image/svg+xml'), UploadedFile::fake()->create('data.txt', 1, 'text/plain'),
            UploadedFile::fake()->image('oversized.jpg')->size(4097)] as $file) {
            $this->postJson(route('search.products.visual'), ['branch_id' => $this->branchId, 'image' => $file])->assertUnprocessable();
        }
    }

    public function test_injected_test_provider_ranks_five_distinct_products_without_exposing_foreign_images_or_cost(): void
    {
        $images = [];
        $expectedProducts = [];
        for ($index = 0; $index < 7; $index++) {
            $variant = $this->stock('35', '20', 2, 'PHOTO'.$index);
            $image = ProductImage::create(['product_id' => $variant->product_id, 'color_id' => $variant->color_id, 'image_path' => 'fixture/photo'.$index.'.webp']);
            $images[] = ['image_id' => $image->id, 'score' => (float) (7 - $index) / 10];
            $expectedProducts[] = $variant->product_id;
        }
        $other = BranchFactory::new()->create();
        $foreign = $this->stock('35', '20', 2, 'FOREIGN');
        DB::table('inventories')->where('product_variant_id', $foreign->id)->update(['branch_id' => $other->id]);
        $foreignImage = ProductImage::create(['product_id' => $foreign->product_id, 'color_id' => $foreign->color_id, 'image_path' => 'fixture/foreign.webp']);
        $allowed = array_column($images, 'image_id');
        array_unshift($images, ['image_id' => $foreignImage->id, 'score' => 1.0]);
        $images[] = $images[1];
        config(['product_search.visual_enabled' => true]);
        $this->app->instance(ImageEmbeddingProvider::class, new class implements ImageEmbeddingProvider
        {
            public function available(): bool
            {
                return true;
            }

            public function version(): string
            {
                return 'test-only';
            }

            public function embed(string $temporaryImage): array
            {
                if (! is_file($temporaryImage)) {
                    throw new \RuntimeException('A local temporary upload is required.');
                }

                return [0.1, 0.2, 0.3];
            }
        });
        $this->app->instance(ImageEmbeddingIndex::class, new class($images, $allowed) implements ImageEmbeddingIndex
        {
            public function __construct(private array $matches, private array $allowed) {}

            public function available(): bool
            {
                return true;
            }

            public function nearest(array $embedding, string $modelVersion, array $allowedImageIds, int $limit): array
            {
                sort($allowedImageIds);
                sort($this->allowed);
                if ($allowedImageIds !== $this->allowed || $modelVersion !== 'test-only' || $limit !== 50) {
                    throw new \RuntimeException('Index scope was not preserved.');
                }

                return $this->matches;
            }
        });
        $viewer = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('inventory_viewer')->id]);
        $this->actingAs($viewer);
        $response = $this->postJson(route('search.products.visual'), ['branch_id' => $this->branchId, 'image' => UploadedFile::fake()->image('camera.jpg')])->assertOk()->assertJsonCount(5, 'data')->assertJsonMissingPath('data.0.variants.0.average_cost');
        $this->assertSame(array_slice($expectedProducts, 0, 5), array_column($response->json('data'), 'product_id'));
        $this->assertSame(2, $response->json('data.0.variants.0.quantity'));
    }

    public function test_unavailable_visual_request_does_not_create_image_records_or_persist_uploads(): void
    {
        $before = DB::table('product_images')->count();
        $upload = UploadedFile::fake()->image('camera-private.jpg');
        $this->postJson(route('search.products.visual'), ['branch_id' => $this->branchId, 'image' => $upload])->assertStatus(503);
        $this->assertSame($before, DB::table('product_images')->count());
        $this->assertDatabaseMissing('product_images', ['image_path' => 'camera-private.jpg']);
    }
}
