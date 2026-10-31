<?php

namespace App\Contracts\Search;

interface ImageEmbeddingIndex
{
    public function available(): bool;

    /** @param list<float> $embedding
     * @param  list<int>  $allowedImageIds
     * @return list<array{image_id: int, score: float}>
     */
    public function nearest(array $embedding, string $modelVersion, array $allowedImageIds, int $limit): array;
}
