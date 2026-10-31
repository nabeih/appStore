<?php

namespace Tests\Feature;

use App\Actions\Search\IndexProductImageEmbeddingAction;
use App\Actions\Search\LocalImageEmbeddingProvider;
use App\Actions\Search\MySqlImageEmbeddingIndex;
use App\Models\Color;
use App\Models\ProductImage;
use App\Models\ProductImageEmbedding;
use App\Models\User;
use App\Support\Sales\SalesAccess;
use App\Support\Search\EmbeddingVector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Feature\Sales\SalesTestCase;

class VisualRuntimeIntegrationTest extends SalesTestCase
{
    private array $sources;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') === 'mysql' && ! in_array(getenv('DB_DATABASE'), ['appstore_fabric_swatch_test', 'appstore_golive_20261031_tests'], true)) {
            throw new RuntimeException('The real-model test requires an approved isolated database.');
        }
        parent::setUp();
        if (getenv('VISUAL_SEARCH_REAL_TEST') !== '1') {
            $this->markTestSkipped('Opt in with VISUAL_SEARCH_REAL_TEST=1 after installing and starting the local model.');
        }
        $manifest = storage_path('app/reports/visual-search-source-images.json');
        if (! is_file($manifest)) {
            $this->markTestSkipped('A read-only source-image manifest is required.');
        }
        $this->sources = collect(json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR))
            ->unique('product_id')->filter(fn (array $source): bool => Storage::disk('public')->exists($source['image_path']))->values()->take(6)->all();
        if (count($this->sources) < 6) {
            $this->markTestSkipped('Six distinct real product photo sources are required.');
        }
        config(['product_search.visual_enabled' => true, 'product_search.indexing_enabled' => true]);
        $this->assertNotEmpty(app(LocalImageEmbeddingProvider::class)->health(), 'Start the local runtime before running real-model integration.');
    }

    public function test_real_onnx_embeddings_are_normalized_repeatable_and_round_trip_through_binary_storage(): void
    {
        $source = $this->sources[0];
        $path = Storage::disk('public')->path($source['image_path']);
        $started = hrtime(true);
        $response = Http::withToken(trim(file_get_contents(config('product_search.token_path'))))
            ->connectTimeout(1)->timeout(12)->post(config('product_search.runtime_url').'/embed', [
                'path' => $path, 'purpose' => 'search', 'version' => config('product_search.model_version')]);
        $this->assertTrue($response->successful());
        $vector = EmbeddingVector::normalize($response->json('embedding'));
        $wallMs = (hrtime(true) - $started) / 1_000_000;
        $again = app(LocalImageEmbeddingProvider::class)->embed($path);
        $decoded = EmbeddingVector::decode(EmbeddingVector::encode($vector), 512);
        $this->assertCount(512, $vector);
        $this->assertEqualsWithDelta(1.0, EmbeddingVector::cosine($vector, $again), 0.00001);
        $this->assertEqualsWithDelta(1.0, EmbeddingVector::cosine($vector, $decoded), 0.00001);
        File::ensureDirectoryExists(storage_path('app/reports'));
        file_put_contents(storage_path('app/reports/visual-search-model-metrics.json'), json_encode([
            'real_model' => true, 'version' => config('product_search.model_version'), 'dimensions' => count($vector),
            'load' => app(LocalImageEmbeddingProvider::class)->health(), 'inference_ms' => $response->json('inference_ms'),
            'cpu_ms' => $response->json('cpu_ms'), 'http_wall_ms' => $wallMs, 'source_image_id' => $source['id'],
            'self_similarity' => EmbeddingVector::cosine($vector, $again),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function test_real_database_queue_indexes_existing_photos_then_returns_five_distinct_actual_photo_matches(): void
    {
        $beforeCompleted = app(LocalImageEmbeddingProvider::class)->health()['completed'];
        $sourceByProduct = [];
        $images = [];
        foreach ($this->sources as $index => $source) {
            $variant = $this->stock(code: 'REAL-AI-'.$index);
            $variant->product->update(['name' => $source['name']]);
            $images[] = ProductImage::create(['product_id' => $variant->product_id, 'color_id' => $variant->color_id,
                'image_path' => $source['image_path']]);
            $sourceByProduct[$variant->product_id] = $source;
        }
        $this->assertSame($beforeCompleted, app(LocalImageEmbeddingProvider::class)->health()['completed'], 'Image saves must never perform inference.');
        $this->assertSame(6, DB::table('jobs')->where('queue', 'ai-indexing')->count());
        $stockBefore = DB::table('inventories')->orderBy('id')->get()->toJson();
        $movementCount = DB::table('stock_movements')->count();
        $failedBefore = DB::table('failed_jobs')->count();
        $start = hrtime(true);
        $exit = Artisan::call('queue:work', ['connection' => 'ai_database', '--queue' => 'ai-indexing',
            '--stop-when-empty' => true, '--max-jobs' => 6, '--sleep' => 0, '--tries' => 3, '--timeout' => 60, '--no-interaction' => true]);
        $this->assertSame(0, $exit, Artisan::output());
        $queueMs = (hrtime(true) - $start) / 1_000_000;
        $this->assertSame(6, ProductImageEmbedding::where('status', 'ready')->count(), Artisan::output());
        $this->assertSame(0, DB::table('jobs')->where('queue', 'ai-indexing')->count());
        $this->assertSame($failedBefore, DB::table('failed_jobs')->count());
        $path = Storage::disk('public')->path($this->sources[0]['image_path']);
        $upload = new UploadedFile($path, 'query.webp', 'image/webp', null, true);
        $queryStart = hrtime(true);
        $response = $this->postJson(route('search.products.visual'), ['branch_id' => $this->branchId, 'image' => $upload])
            ->assertOk()->assertJsonCount(5, 'data');
        $queryMs = (hrtime(true) - $queryStart) / 1_000_000;
        $matches = $response->json('data');
        $indexed = ProductImageEmbedding::where('status', 'ready')->firstOrFail();
        $comparisonStart = hrtime(true);
        app(MySqlImageEmbeddingIndex::class)->nearest(EmbeddingVector::decode($indexed->embedding, 512), config('product_search.model_version'), array_map(fn ($image): int => $image->id, $images), 5);
        $comparisonMs = (hrtime(true) - $comparisonStart) / 1_000_000;
        $this->assertCount(5, array_unique(array_column($matches, 'product_id')));
        $this->assertContains($images[0]->product_id, array_column($matches, 'product_id'));
        $this->assertSame($stockBefore, DB::table('inventories')->orderBy('id')->get()->toJson());
        $this->assertSame($movementCount, DB::table('stock_movements')->count());
        $this->assertFileExists($path);
        file_put_contents(storage_path('app/reports/visual-search-real-results.json'), json_encode([
            'real_model' => true, 'isolated_database' => DB::connection()->getDatabaseName(), 'queue' => 'ai-indexing',
            'indexed_images' => 6, 'queue_total_ms' => $queueMs, 'query_http_ms' => $queryMs, 'comparison_ms' => $comparisonMs,
            'query_source_product_id' => $this->sources[0]['product_id'],
            'matches' => array_map(fn (array $match): array => [
                'source_product_id' => $sourceByProduct[$match['product_id']]['product_id'],
                'name' => $sourceByProduct[$match['product_id']]['name'], 'score' => $match['score'],
            ], $matches),
            'phone_photos_tested' => false, 'recall_at_5' => null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $preview = storage_path('app/reports/visual-search-preview');
        File::ensureDirectoryExists($preview);
        $viewer = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('inventory_viewer')->id]);
        $this->actingAs($viewer);
        $viewerResponse = $this->postJson(route('search.products.visual'), ['branch_id' => $this->branchId, 'image' => $upload])
            ->assertOk()->assertJsonCount(5, 'data')->assertJsonMissingPath('data.0.variants.0.average_cost')
            ->assertJsonPath('data.0.variants.0.sale_url', null);
        file_put_contents($preview.'/search.html', $this->get(route('search.index', ['type' => 'products']))->assertOk()->getContent());
        file_put_contents($preview.'/products.json', $this->getJson(route('search.products'))->assertOk()->getContent());
        file_put_contents($preview.'/visual.json', $viewerResponse->getContent());
    }

    public function test_thirty_real_photo_jobs_run_serially_from_database_queue_after_saves_complete(): void
    {
        $colors = [Color::firstOrCreate(['name' => 'أسود'], ['code' => 'BLK']),
            Color::firstOrCreate(['name' => 'أحمر'], ['code' => 'RED']), Color::firstOrCreate(['name' => 'أزرق'], ['code' => 'BLU'])];
        $provider = app(LocalImageEmbeddingProvider::class);
        $before = $provider->health()['completed'];
        for ($index = 0; $index < 10; $index++) {
            $variant = $this->stock(code: 'REAL-BATCH-'.$index);
            foreach ($colors as $colorIndex => $color) {
                $source = $this->sources[($index + $colorIndex) % count($this->sources)];
                ProductImage::create(['product_id' => $variant->product_id, 'color_id' => $color->id, 'image_path' => $source['image_path']]);
            }
        }
        $this->assertSame($before, $provider->health()['completed']);
        $this->assertSame(30, DB::table('jobs')->where('queue', 'ai-indexing')->count());
        $failed = DB::table('failed_jobs')->count();
        $start = hrtime(true);
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'ai_database', '--queue' => 'ai-indexing',
            '--stop-when-empty' => true, '--max-jobs' => 30, '--sleep' => 0, '--tries' => 3, '--timeout' => 60, '--no-interaction' => true]));
        $this->assertSame(30, ProductImageEmbedding::where('status', 'ready')->count(), Artisan::output());
        $this->assertSame(0, DB::table('jobs')->where('queue', 'ai-indexing')->count());
        $this->assertSame($failed, DB::table('failed_jobs')->count());
        $inferences = $provider->health()['completed'] - $before;
        $this->assertGreaterThan(0, $inferences);
        $this->assertLessThan(30, $inferences, 'Repeated image content should reuse its indexed vector.');
        file_put_contents(storage_path('app/reports/visual-search-thirty-jobs.json'), json_encode([
            'real_database_queue' => true, 'real_model' => true, 'products' => 10, 'images' => 30, 'ready' => 30,
            'inferences_during_save' => 0, 'actual_inferences' => $inferences, 'content_cache_reused' => 30 - $inferences,
            'worker_count' => 1, 'model_threads' => 1, 'elapsed_ms' => (hrtime(true) - $start) / 1_000_000,
        ], JSON_PRETTY_PRINT));
    }

    public function test_normal_laravel_search_remains_responsive_during_real_indexing_inference(): void
    {
        $this->stock(code: 'LOAD-PROBE');
        $measure = function (): float {
            $start = hrtime(true);
            $this->getJson(route('search.products'))->assertOk();

            return (hrtime(true) - $start) / 1_000_000;
        };
        $baseline = array_map(fn (): float => $measure(), range(1, 8));
        $code = <<<'PYTHON'
import json,sys,time,urllib.request
from pathlib import Path
path,endpoint,token_file=sys.argv[1:]
token=Path(token_file).read_text().strip()
deadline=time.monotonic()+3
count=0
print('LOAD_STARTED',flush=True)
while time.monotonic()<deadline:
    request=urllib.request.Request(endpoint+'/embed',data=json.dumps({'path':path,'purpose':'index','version':'clip-b32-int8-583fd111-clip-preprocess-v1'}).encode(),headers={'Authorization':'Bearer '+token,'Content-Type':'application/json'})
    with urllib.request.urlopen(request,timeout=5) as response:
        json.load(response)
    count+=1
print(json.dumps({'inferences':count}),flush=True)
PYTHON;
        $probe = new Process([config('product_search.python'), '-c', $code, Storage::disk('public')->path($this->sources[0]['image_path']),
            config('product_search.runtime_url'), config('product_search.token_path')], base_path());
        $probe->setTimeout(12);
        $busy = [];
        try {
            $probe->start();
            $this->assertTrue($probe->waitUntil(fn (): bool => str_contains($probe->getOutput(), 'LOAD_STARTED')));
            $deadline = microtime(true) + 2;
            do {
                $active = app(LocalImageEmbeddingProvider::class)->health()['active'] ?? null;
                if ($active === 'index') {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame('index', $active);
            $busy = array_map(fn (): float => $measure(), range(1, 8));
            $this->assertLessThan(2000.0, max($busy));
            $this->assertSame(0, $probe->wait());
        } finally {
            if ($probe->isRunning()) {
                $probe->stop(1);
            }
        }
        file_put_contents(storage_path('app/reports/visual-search-laravel-load.json'), json_encode([
            'real_model_load' => true, 'endpoint' => '/search/products', 'baseline_ms' => $baseline,
            'during_indexing_ms' => $busy, 'probe_output' => trim($probe->getOutput()),
            'scope' => 'Eight sequential in-process Laravel feature requests; not a production concurrency benchmark.',
        ], JSON_PRETTY_PRINT));
    }

    public function test_worker_reclaims_an_expired_reservation_and_replay_does_not_repeat_inference(): void
    {
        $variant = $this->stock(code: 'RESTART-AI');
        $image = ProductImage::create(['product_id' => $variant->product_id, 'color_id' => $variant->color_id,
            'image_path' => $this->sources[0]['image_path']]);
        $row = ProductImageEmbedding::where('product_image_id', $image->id)->firstOrFail();
        $reserved = Queue::connection('ai_database')->pop('ai-indexing');
        $this->assertNotNull($reserved);
        $this->assertSame(1, $reserved->attempts());
        $row->update(['status' => 'processing']);
        DB::table('jobs')->where('id', $reserved->getJobId())->update([
            'reserved_at' => now()->subSeconds(config('queue.connections.ai_database.retry_after') + 1)->getTimestamp(),
        ]);
        $before = app(LocalImageEmbeddingProvider::class)->health()['completed'];
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'ai_database', '--queue' => 'ai-indexing',
            '--once' => true, '--sleep' => 0, '--tries' => 3, '--timeout' => 60, '--no-interaction' => true]));
        $this->assertSame('ready', $row->fresh()->status, Artisan::output());
        $this->assertSame(0, DB::table('jobs')->where('queue', 'ai-indexing')->count());
        $after = app(LocalImageEmbeddingProvider::class)->health()['completed'];
        $this->assertSame(1, $after - $before);
        app(IndexProductImageEmbeddingAction::class)->execute($row->id, $row->generation);
        $this->assertSame($after, app(LocalImageEmbeddingProvider::class)->health()['completed']);
        file_put_contents(storage_path('app/reports/visual-search-queue-restart.json'), json_encode([
            'real_model' => true, 'real_database_queue' => true, 'expired_reservation_reclaimed' => true,
            'processing_state_resumed' => true, 'inferences' => 1, 'replay_extra_inferences' => 0,
            'interruption_simulated_by_aging_reservation' => true,
        ], JSON_PRETTY_PRINT));
    }

    public function test_real_garment_images_match_a_transformed_query_with_the_actual_model(): void
    {
        $manifest = storage_path('app/reports/visual-search-clothing-fixtures.json');
        if (! is_file($manifest)) {
            $this->markTestSkipped('Prepare the optional licensed garment fixtures first.');
        }
        $fixtures = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $directory = storage_path('framework/cache/visual-search/clothing-fixtures');
        config(['filesystems.disks.public.root' => $directory, 'filesystems.disks.public.url' => 'http://localhost:8000/visual-fixtures']);
        Storage::forgetDisk('public');
        $labels = [];
        $targetProduct = null;
        foreach ($fixtures['images'] as $sample) {
            $variant = $this->stock(code: 'GARMENT-'.$sample['label']);
            $variant->product->update(['name' => $sample['name']]);
            $labels[$variant->product_id] = $sample;
            if ($sample['label'] === $fixtures['query_source_label']) {
                $targetProduct = $variant->product_id;
            }
            ProductImage::create(['product_id' => $variant->product_id, 'color_id' => $variant->color_id, 'image_path' => $sample['image_path']]);
        }
        $this->assertSame(10, DB::table('jobs')->where('queue', 'ai-indexing')->count());
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'ai_database', '--queue' => 'ai-indexing',
            '--stop-when-empty' => true, '--max-jobs' => 10, '--sleep' => 0, '--tries' => 3, '--timeout' => 60, '--no-interaction' => true]));
        $this->assertSame(10, ProductImageEmbedding::where('status', 'ready')->count(), Artisan::output());
        $viewer = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('inventory_viewer')->id]);
        $this->actingAs($viewer);
        $query = new UploadedFile($directory.'/'.$fixtures['query'], 'query.png', 'image/png', null, true);
        $response = $this->postJson(route('search.products.visual'), ['branch_id' => $this->branchId, 'image' => $query])
            ->assertOk()->assertJsonCount(5, 'data')->assertJsonMissingPath('data.0.variants.0.average_cost')->assertJsonPath('data.0.variants.0.sale_url', null);
        $matches = $response->json('data');
        $ids = array_column($matches, 'product_id');
        $this->assertCount(5, array_unique($ids));
        $this->assertContains($targetProduct, $ids);
        file_put_contents(storage_path('app/reports/visual-search-clothing-results.json'), json_encode([
            'real_model' => true, 'source' => $fixtures['source'], 'retailer_images' => true, 'original_resolution' => '28x28 grayscale',
            'catalogue_images' => 10, 'query_transform' => $fixtures['query_transform'],
            'expected_label' => $fixtures['query_source_label'], 'target_rank' => array_search($targetProduct, $ids, true) + 1,
            'matches' => array_map(fn (array $match): array => ['label' => $labels[$match['product_id']]['label'],
                'name' => $labels[$match['product_id']]['name'], 'score' => $match['score']], $matches),
            'phone_photographs_tested' => false, 'phone_recall_at_5' => null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $preview = storage_path('app/reports/visual-search-preview');
        File::ensureDirectoryExists($preview);
        file_put_contents($preview.'/search.html', $this->get(route('search.index', ['type' => 'products']))->assertOk()->getContent());
        file_put_contents($preview.'/products.json', $this->getJson(route('search.products'))->assertOk()->getContent());
        file_put_contents($preview.'/visual.json', $response->getContent());
    }
}
