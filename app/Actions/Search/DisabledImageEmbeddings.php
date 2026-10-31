<?php

namespace App\Actions\Search;

use App\Contracts\Search\ImageEmbeddingIndex;
use App\Contracts\Search\ImageEmbeddingProvider;
use LogicException;

class DisabledImageEmbeddings implements ImageEmbeddingIndex, ImageEmbeddingProvider
{
    public function available(): bool
    {
        return false;
    }

    public function version(): string
    {
        return 'disabled';
    }

    public function embed(string $temporaryImage): array
    {
        throw new LogicException('No image embedding provider has been approved or installed.');
    }

    public function nearest(array $embedding, string $modelVersion, array $allowedImageIds, int $limit): array
    {
        throw new LogicException('No visual search index has been configured.');
    }
}
