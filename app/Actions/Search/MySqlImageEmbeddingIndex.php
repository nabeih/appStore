<?php

namespace App\Actions\Search;

use App\Contracts\Search\ImageEmbeddingIndex;
use App\Models\ProductImageEmbedding;
use App\Support\Search\EmbeddingVector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class MySqlImageEmbeddingIndex implements ImageEmbeddingIndex
{
    public function available(): bool
    {
        return (bool) config('product_search.visual_enabled') && Schema::hasTable('product_image_embeddings');
    }

    public function nearest(array $embedding, string $modelVersion, array $allowedImageIds, int $limit): array
    {
        $embedding = EmbeddingVector::normalize($embedding);
        if (! $this->available() || $allowedImageIds === []) {
            return [];
        }
        $best = [];
        ProductImageEmbedding::query()->where('model_version', $modelVersion)->where('status', 'ready')
            ->where('dimensions', count($embedding))->whereNotNull('embedding')->whereIn('product_image_id', $allowedImageIds)
            ->whereHas('image', fn (Builder $image) => $image->whereColumn('product_images.image_path', 'product_image_embeddings.image_path'))
            ->with('image:id,product_id')->chunkById(128, function ($rows) use ($embedding, &$best): void {
                foreach ($rows as $row) {
                    try {
                        $vector = EmbeddingVector::decode($row->embedding, $row->dimensions);
                    } catch (InvalidArgumentException) {
                        continue;
                    }
                    $score = EmbeddingVector::cosine($embedding, $vector);
                    $productId = $row->image->product_id;
                    if (! isset($best[$productId]) || $score > $best[$productId]['score']) {
                        $best[$productId] = ['image_id' => $row->product_image_id, 'score' => $score];
                    }
                }
            });
        usort($best, fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: ($left['image_id'] <=> $right['image_id']));

        return array_slice($best, 0, max(1, min(50, $limit)));
    }
}
