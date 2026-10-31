<?php

namespace App\Contracts\Search;

interface ImageEmbeddingProvider
{
    public function available(): bool;

    public function version(): string;

    /** @return list<float> Normalized image embedding; the path is a validated local upload temporary file. */
    public function embed(string $temporaryImage): array;
}
