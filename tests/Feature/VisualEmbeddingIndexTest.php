<?php

namespace Tests\Feature;

use App\Actions\Search\IndexProductImageEmbeddingAction;
use App\Actions\Search\LocalImageEmbeddingProvider;
use App\Actions\Search\MySqlImageEmbeddingIndex;
use App\Actions\Search\QueueImageEmbeddingAction;
use App\Contracts\Search\ImageEmbeddingProvider;
use App\Jobs\Search\IndexProductImageEmbeddingJob;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\Permission;
use App\Models\ProductImage;
use App\Models\ProductImageEmbedding;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Sales\SalesAccess;
use App\Support\Search\EmbeddingVector;
use App\Support\Search\LocalProductImagePath;
use App\Support\Search\PreventConcurrentImageIndexing;
use Closure;
use Database\Factories\BranchFactory;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Sales\SalesTestCase;

class VisualEmbeddingIndexTest extends SalesTestCase
{
    private object $provider;

    private string $tokenPath;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') === 'mysql' && getenv('DB_DATABASE') !== 'appstore_fabric_swatch_test') {
            throw new RuntimeException('An approved isolated test database is required.');
        }
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        config(['product_search.visual_enabled' => true, 'product_search.indexing_enabled' => false]);
        File::ensureDirectoryExists(storage_path('framework/testing'));
        $this->tokenPath = storage_path('framework/testing/visual-token-'.Str::uuid());
        file_put_contents($this->tokenPath, str_repeat('a', 64));
        config(['product_search.token_path' => $this->tokenPath]);
        $this->provider = new class implements ImageEmbeddingProvider
        {
            public int $calls = 0;

            public ?Closure $callback = null;

            public function available(): bool
            {
                return true;
            }

            public function version(): string
            {
                return config('product_search.model_version');
            }

            public function embed(string $temporaryImage): array
            {
                $this->calls++;
                if ($this->callback) {
                    ($this->callback)();
                }

                return [1.0, ...array_fill(0, 511, 0.0)];
            }
        };
        $this->app->instance(ImageEmbeddingProvider::class, $this->provider);
    }

    protected function tearDown(): void
    {
        if (isset($this->tokenPath) && is_file($this->tokenPath)) {
            unlink($this->tokenPath);
        }
        parent::tearDown();
    }

    private function image(?ProductVariant $variant = null, ?int $colorId = null): ProductImage
    {
        $variant ??= $this->stock(code: 'IMAGE'.Str::upper(Str::random(8)));
        $path = 'products/'.Str::uuid().'.jpg';
        Storage::disk('public')->put($path, $this->imageBytes());

        return ProductImage::create(['product_id' => $variant->product_id, 'color_id' => $colorId ?? $variant->color_id, 'image_path' => $path]);
    }

    private function imageBytes(int $width = 30, int $height = 40): string
    {
        $file = UploadedFile::fake()->image('garment.jpg', $width, $height);

        return file_get_contents($file->getPathname());
    }

    private function schedule(ProductImage $image): ProductImageEmbedding
    {
        config(['product_search.indexing_enabled' => true]);
        app(QueueImageEmbeddingAction::class)->execute($image->id);

        return ProductImageEmbedding::where('product_image_id', $image->id)->where('model_version', config('product_search.model_version'))->firstOrFail();
    }

    private function runIndex(ProductImageEmbedding $row): void
    {
        app(IndexProductImageEmbeddingAction::class)->execute($row->id, $row->generation);
    }

    private function ready(ProductImage $image, float $score): ProductImageEmbedding
    {
        $vector = [$score, sqrt(1 - $score * $score), ...array_fill(0, 510, 0.0)];

        return ProductImageEmbedding::factory()->create(['product_image_id' => $image->id, 'image_path' => $image->image_path,
            'status' => 'ready', 'dimensions' => 512, 'embedding' => EmbeddingVector::encode($vector),
            'image_hash' => hash_file('sha256', Storage::disk('public')->path($image->image_path)), 'indexed_at' => now()]);
    }

    public function test_thirty_images_dispatch_after_commit_to_the_dedicated_queue_without_any_inference(): void
    {
        config(['product_search.indexing_enabled' => true]);
        $colors = [Color::firstOrCreate(['name' => 'أسود'], ['code' => 'BLK']),
            Color::firstOrCreate(['name' => 'أحمر'], ['code' => 'RED']), Color::firstOrCreate(['name' => 'أزرق'], ['code' => 'BLU'])];
        for ($index = 0; $index < 10; $index++) {
            $variant = $this->stock(code: 'BATCH'.$index);
            DB::transaction(function () use ($variant, $colors, $index): void {
                foreach ($colors as $color) {
                    $this->image($variant, $color->id);
                }
                Queue::assertPushed(IndexProductImageEmbeddingJob::class, $index * 3);
            });
        }
        Queue::assertPushed(IndexProductImageEmbeddingJob::class, 30);
        Queue::assertPushed(IndexProductImageEmbeddingJob::class, fn ($job): bool => $job->connection === 'ai_database' && $job->queue === 'ai-indexing');
        $this->assertSame(0, $this->provider->calls);
        $this->assertDatabaseCount('product_image_embeddings', 30);
    }

    public function test_rolled_back_image_save_never_dispatches_a_job(): void
    {
        $variant = $this->stock();
        config(['product_search.indexing_enabled' => true]);
        try {
            DB::transaction(function () use ($variant): void {
                $this->image($variant);
                throw new RuntimeException('rollback fixture');
            });
        } catch (RuntimeException) {
        }
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('product_image_embeddings', 0);
    }

    public function test_duplicate_job_and_unchanged_reindex_do_not_repeat_inference_or_change_stock(): void
    {
        $image = $this->image();
        $row = $this->schedule($image);
        $quantities = DB::table('inventories')->pluck('quantity', 'id')->all();
        $movements = DB::table('stock_movements')->count();
        $transactionLevel = DB::transactionLevel();
        $this->provider->callback = fn () => $this->assertSame($transactionLevel, DB::transactionLevel());
        $this->runIndex($row);
        $this->runIndex($row);
        $this->assertFalse(app(QueueImageEmbeddingAction::class)->execute($image->id, true));
        $this->assertSame(1, $this->provider->calls);
        $this->assertSame('ready', $row->fresh()->status);
        $this->assertSame(2048, strlen($row->fresh()->embedding));
        $this->assertSame($quantities, DB::table('inventories')->pluck('quantity', 'id')->all());
        $this->assertSame($movements, DB::table('stock_movements')->count());
        Queue::assertPushed(IndexProductImageEmbeddingJob::class, 1);
    }

    public function test_replacement_while_waiting_invalidates_old_jobs(): void
    {
        $image = $this->image();
        $old = $this->schedule($image);
        Storage::disk('public')->put('products/replaced.jpg', $this->imageBytes(35, 45));
        $image->update(['image_path' => 'products/replaced.jpg']);
        $new = $old->fresh();
        $this->assertNotSame($old->generation, $new->generation);
        $this->runIndex($old);
        $this->assertSame(0, $this->provider->calls);
        $this->runIndex($new);
        $this->assertSame('ready', $new->fresh()->status);
    }

    public function test_replacement_during_inference_cannot_publish_old_output(): void
    {
        $image = $this->image();
        $old = $this->schedule($image);
        Storage::disk('public')->put('products/during.jpg', $this->imageBytes(35, 45));
        $this->provider->callback = function () use ($image): void {
            $this->provider->callback = null;
            $image->update(['image_path' => 'products/during.jpg']);
        };
        $this->runIndex($old);
        $this->assertSame('pending', $old->fresh()->status);
        $this->assertNull($old->fresh()->embedding);
        $this->runIndex($old->fresh());
        $this->assertSame('ready', $old->fresh()->status);
    }

    public function test_same_path_content_change_is_detected_after_inference_and_requeued(): void
    {
        $image = $this->image();
        $old = $this->schedule($image);
        $this->provider->callback = function () use ($image): void {
            $this->provider->callback = null;
            Storage::disk('public')->put($image->image_path, $this->imageBytes(40, 60));
        };
        $this->runIndex($old);
        $this->assertNotSame($old->generation, $old->fresh()->generation);
        $this->assertSame('pending', $old->fresh()->status);
        $this->assertNull($old->fresh()->embedding);
    }

    public function test_deleted_image_is_safe_before_and_during_inference(): void
    {
        $image = $this->image();
        $row = $this->schedule($image);
        $image->delete();
        $this->runIndex($row);
        $this->assertSame(0, $this->provider->calls);
        $this->assertDatabaseCount('product_image_embeddings', 0);
        $image = $this->image();
        $row = $this->schedule($image);
        $this->provider->callback = fn () => $image->delete();
        $this->runIndex($row);
        $this->assertDatabaseCount('product_image_embeddings', 0);
    }

    public function test_crashed_processing_state_is_resumable_and_transient_failure_can_retry(): void
    {
        $image = $this->image();
        $row = $this->schedule($image);
        $row->update(['status' => 'processing']);
        $job = new IndexProductImageEmbeddingJob($row->id, $row->generation);
        $this->provider->callback = function (): void {
            throw new RuntimeException('temporary failure');
        };
        try {
            $job->handle(app(IndexProductImageEmbeddingAction::class));
            $this->fail('The failed inference must be retried by the worker.');
        } catch (RuntimeException) {
            $this->assertSame('pending', $row->fresh()->status);
        }
        $this->provider->callback = null;
        $job->handle(app(IndexProductImageEmbeddingAction::class));
        $this->assertSame('ready', $row->fresh()->status);
        $this->assertNull($row->fresh()->last_error);
        $this->assertSame(3, $job->tries);
        $this->assertLessThan(config('queue.connections.ai_database.retry_after'), $job->timeout);
        $this->assertSame([10, 60, 180], $job->backoff());
        $this->assertCount(1, $job->middleware());
    }

    public function test_failed_callback_cannot_overwrite_a_new_generation_and_model_versions_coexist(): void
    {
        $image = $this->image();
        $old = $this->schedule($image);
        $job = new IndexProductImageEmbeddingJob($old->id, $old->generation);
        $job->failed(new RuntimeException('fixture failure'));
        $this->assertSame('failed', $old->fresh()->status);
        app(QueueImageEmbeddingAction::class)->execute($image->id, true);
        $job->failed(new RuntimeException('late old failure'));
        $this->assertSame('pending', $old->fresh()->status);
        $this->runIndex($old->fresh());
        config(['product_search.model_version' => 'future-model-fixture']);
        $this->schedule($image);
        $this->assertDatabaseCount('product_image_embeddings', 2);
        $this->assertSame('ready', $old->fresh()->status);
    }

    public function test_vector_payload_is_normalized_and_rejects_corrupt_or_non_finite_data(): void
    {
        $values = [3.0, 4.0, ...array_fill(0, 510, 0.0)];
        $decoded = EmbeddingVector::decode(EmbeddingVector::encode($values), 512);
        $this->assertEqualsWithDelta(1.0, EmbeddingVector::cosine($decoded, $decoded), 0.000001);
        foreach ([[NAN, ...array_fill(0, 511, 0.0)], array_fill(0, 512, 0.0)] as $invalid) {
            try {
                EmbeddingVector::encode($invalid);
                $this->fail('Invalid vectors must not be persisted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        EmbeddingVector::decode('corrupt', 512);
    }

    public function test_index_ranks_unique_products_and_excludes_unready_wrong_version_and_unscoped_images(): void
    {
        $allowed = [];
        $expected = [];
        for ($index = 0; $index < 7; $index++) {
            $image = $this->image();
            $this->ready($image, 0.95 - $index * 0.1);
            $allowed[] = $image->id;
            $expected[] = $image->id;
            if ($index === 0) {
                $red = Color::firstOrCreate(['name' => 'أحمر'], ['code' => 'RED']);
                $duplicate = $this->image(ProductVariant::where('product_id', $image->product_id)->firstOrFail(), $red->id);
                $this->ready($duplicate, 0.94);
                $allowed[] = $duplicate->id;
            }
        }
        $foreign = $this->image();
        $this->ready($foreign, 0.999);
        $wrong = $this->image();
        $this->ready($wrong, 0.999)->update(['model_version' => 'other-version']);
        $allowed[] = $wrong->id;
        $pending = $this->image();
        $this->ready($pending, 0.999)->update(['status' => 'pending']);
        $allowed[] = $pending->id;
        $matches = app(MySqlImageEmbeddingIndex::class)->nearest([1.0, ...array_fill(0, 511, 0.0)], config('product_search.model_version'), $allowed, 5);
        $this->assertSame(array_slice($expected, 0, 5), array_column($matches, 'image_id'));
        $this->assertCount(5, $matches);
    }

    public function test_provider_cleans_search_temporary_files_on_success_and_runtime_failure(): void
    {
        Http::preventStrayRequests();
        $source = UploadedFile::fake()->image('query.jpg');
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request['path'];
            $this->assertFileExists($request['path']);
            $this->assertSame('search', $request['purpose']);

            return Http::response(['version' => config('product_search.model_version'), 'embedding' => [1.0, ...array_fill(0, 511, 0.0)]]);
        });
        app(LocalImageEmbeddingProvider::class)->embed($source->getPathname());
        $this->assertFileDoesNotExist($seen[0]);
        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request['path'];

            return Http::response(['error' => 'runtime_busy'], 503);
        });
        try {
            app(LocalImageEmbeddingProvider::class)->embed($source->getPathname());
            $this->fail('The busy runtime must fail clearly.');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($seen[1]);
        }
    }

    public function test_storage_traversal_and_non_loopback_runtime_are_rejected(): void
    {
        foreach (['../.env', '/etc/passwd', 'C:/Windows/win.ini', 'products/../../.env'] as $path) {
            try {
                app(LocalProductImagePath::class)->resolve($path);
                $this->fail('Traversal must be rejected.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        Http::preventStrayRequests();
        config(['product_search.runtime_url' => 'https://example.com']);
        $source = UploadedFile::fake()->image('query.jpg');
        $this->expectException(RuntimeException::class);
        app(LocalImageEmbeddingProvider::class)->embed($source->getPathname());
    }

    public function test_admin_index_and_retry_are_permission_and_branch_scoped(): void
    {
        $local = $this->schedule($this->image());
        $foreignVariant = $this->stock(code: 'FOREIGN-VISUAL');
        $foreignBranch = BranchFactory::new()->create();
        Inventory::where('product_variant_id', $foreignVariant->id)->update(['branch_id' => $foreignBranch->id]);
        $foreign = $this->schedule($this->image($foreignVariant));
        $viewer = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('inventory_viewer')->id]);
        $this->actingAs($viewer);
        $this->get(route('search.visual-index'))->assertForbidden();
        $this->post(route('search.visual-index.retry', $local->id))->assertForbidden();
        $viewer->role->permissions()->attach(Permission::where('name', 'inventory.manage_catalog')->firstOrFail()->id);
        $viewer->unsetRelation('role');
        Http::fake(['127.0.0.1:8765/health' => Http::response(['version' => config('product_search.model_version')])]);
        $this->get(route('search.visual-index'))->assertOk()->assertDontSee($foreignVariant->product->name);
        $this->post(route('search.visual-index.retry', $foreign->id))->assertNotFound();
        $this->post(route('search.visual-index.retry', $local->id))->assertRedirect(route('search.visual-index'));
    }

    public function test_missing_product_image_is_reported_without_unsafe_file_access(): void
    {
        $image = $this->image();
        Storage::disk('public')->delete($image->image_path);
        $row = $this->schedule($image);
        $this->assertSame('failed', $row->status);
        Queue::assertNothingPushed();
    }

    public function test_small_initial_batches_resume_past_already_queued_images(): void
    {
        $this->image();
        $this->image();
        $this->image();
        config(['product_search.indexing_enabled' => true]);
        for ($index = 1; $index <= 3; $index++) {
            $this->artisan('app:images-index', ['--chunk' => 1, '--limit' => 1])->assertSuccessful();
            Queue::assertPushed(IndexProductImageEmbeddingJob::class, $index);
        }
        $this->artisan('app:images-index', ['--chunk' => 1, '--limit' => 1])->assertSuccessful();
        Queue::assertPushed(IndexProductImageEmbeddingJob::class, 3);
    }

    public function test_identical_content_reuses_a_real_persisted_vector_without_repeating_analysis(): void
    {
        $first = $this->schedule($this->image());
        $second = $this->schedule($this->image());
        $this->assertSame($first->image_hash, $second->image_hash);
        $this->runIndex($first);
        $this->runIndex($second);
        $this->assertSame(1, $this->provider->calls);
        $this->assertSame($first->fresh()->embedding, $second->fresh()->embedding);
        $this->assertSame('ready', $second->fresh()->status);
    }

    public function test_actual_product_image_upload_only_enqueues_background_indexing(): void
    {
        $variant = $this->stock();
        config(['product_search.indexing_enabled' => true]);
        $this->postJson(route('catalog.product-images.store', $variant->product_id), ['images' => [
            ['color_id' => $variant->color_id, 'image' => UploadedFile::fake()->image('upload.jpg')],
        ]])->assertOk();
        Queue::assertPushed(IndexProductImageEmbeddingJob::class, 1);
        $this->assertSame(0, $this->provider->calls);
        $this->assertDatabaseCount('product_image_embeddings', 1);
    }

    public function test_shared_file_lock_releases_a_duplicate_job_without_running_it(): void
    {
        $record = $this->schedule($this->image());
        $job = (new IndexProductImageEmbeddingJob($record->id, $record->generation))->withFakeQueueInteractions();
        $mutex = new PreventConcurrentImageIndexing;
        $lock = Cache::store('file')->lock($mutex->lockKey($job), 90);
        $this->assertTrue($lock->get());
        $called = false;
        try {
            $mutex->handle($job, function () use (&$called): void {
                $called = true;
            });
            $this->assertFalse($called);
            $job->assertReleased(5);
        } finally {
            $lock->release();
        }
        $mutex->handle($job, function () use (&$called): void {
            $called = true;
        });
        $this->assertTrue($called);
    }

    public function test_extreme_aspect_ratio_is_rejected_before_running_the_model(): void
    {
        $this->postJson(route('search.products.visual'), ['branch_id' => $this->branchId,
            'image' => UploadedFile::fake()->image('strip.jpg', 2048, 1)])->assertStatus(422);
        $this->assertSame(0, $this->provider->calls);
    }

    public function test_expired_search_files_can_be_pruned_without_deleting_current_or_unrelated_files(): void
    {
        $directory = storage_path('framework/cache/visual-search');
        File::ensureDirectoryExists($directory);
        $old = $directory.'/'.bin2hex(random_bytes(20)).'.img';
        $current = $directory.'/'.bin2hex(random_bytes(20)).'.img';
        $unrelated = $directory.'/unrelated-'.Str::uuid().'.img';
        foreach ([$old, $current, $unrelated] as $file) {
            file_put_contents($file, 'fixture');
        }
        touch($old, time() - 700);
        touch($unrelated, time() - 700);
        try {
            app(LocalImageEmbeddingProvider::class)->pruneTemporaryImages();
            $this->assertFileDoesNotExist($old);
            $this->assertFileExists($current);
            $this->assertFileExists($unrelated);
        } finally {
            File::delete([$old, $current, $unrelated]);
        }
    }

    public function test_failed_job_enqueue_rolls_back_index_metadata_without_affecting_the_saved_image(): void
    {
        $image = $this->image();
        config(['product_search.indexing_enabled' => true]);
        $this->mock(Dispatcher::class)->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));
        try {
            app(QueueImageEmbeddingAction::class)->execute($image->id);
            $this->fail('A failed enqueue must not leave orphan pending metadata.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('product_image_embeddings', 0);
            $this->assertNotNull($image->fresh());
            Storage::disk('public')->assertExists($image->image_path);
        }
    }
}
