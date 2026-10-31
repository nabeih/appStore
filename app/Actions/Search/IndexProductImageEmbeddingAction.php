<?php

namespace App\Actions\Search;

use App\Contracts\Search\ImageEmbeddingProvider;
use App\Models\ProductImage;
use App\Models\ProductImageEmbedding;
use App\Support\Search\EmbeddingExecutionContext;
use App\Support\Search\EmbeddingVector;
use App\Support\Search\LocalProductImagePath;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class IndexProductImageEmbeddingAction
{
    public function __construct(private ImageEmbeddingProvider $provider, private EmbeddingExecutionContext $context,
        private LocalProductImagePath $paths, private QueueImageEmbeddingAction $queue) {}

    public function execute(int $embeddingId, string $generation): void
    {
        $record = ProductImageEmbedding::with('image')->find($embeddingId);
        if (! $record || ! $record->image || $record->generation !== $generation || $record->model_version !== $this->provider->version()) {
            return;
        }
        if ($record->image_path !== $record->image->image_path) {
            return;
        }
        $path = $this->paths->resolve($record->image_path);
        $hash = hash_file('sha256', $path);
        if (! hash_equals($record->image_hash, $hash)) {
            $this->queue->execute($record->product_image_id, true);

            return;
        }
        if ($record->status === 'ready') {
            return;
        }
        $started = DB::transaction(function () use ($record, $generation): bool {
            $image = ProductImage::whereKey($record->product_image_id)->lockForUpdate()->first();
            $locked = ProductImageEmbedding::whereKey($record->id)->lockForUpdate()->first();
            if (! $image || ! $locked || $locked->generation !== $generation || $image->image_path !== $locked->image_path) {
                return false;
            }
            $locked->update(['status' => 'processing', 'last_error' => null]);

            return true;
        }, 3);
        if (! $started) {
            return;
        }
        $cached = ProductImageEmbedding::where('model_version', $record->model_version)->where('image_hash', $hash)
            ->where('status', 'ready')->where('dimensions', 512)->whereNotNull('embedding')->whereKeyNot($record->id)->first();
        $vector = null;
        if ($cached) {
            try {
                $vector = EmbeddingVector::decode($cached->embedding, $cached->dimensions);
            } catch (InvalidArgumentException) {
                $vector = null;
            }
        }
        $vector ??= $this->context->indexing(fn (): array => $this->provider->embed($path));
        $payload = EmbeddingVector::encode($vector);
        clearstatcache(true, $path);
        $afterHash = is_file($path) ? hash_file('sha256', $path) : null;
        if ($afterHash !== $hash) {
            $this->queue->execute($record->product_image_id, true);

            return;
        }
        DB::transaction(function () use ($record, $generation, $hash, $payload, $vector): void {
            $image = ProductImage::whereKey($record->product_image_id)->lockForUpdate()->first();
            $locked = ProductImageEmbedding::whereKey($record->id)->lockForUpdate()->first();
            if (! $image || ! $locked || $locked->generation !== $generation
                || $image->image_path !== $record->image_path || ! hash_equals($locked->image_hash, $hash)) {
                return;
            }
            $locked->update(['status' => 'ready', 'embedding' => $payload, 'dimensions' => count($vector),
                'indexed_at' => now(), 'last_error' => null]);
        }, 3);
    }
}
