<?php

namespace App\Support\Search;

use Closure;

class EmbeddingExecutionContext
{
    public string $purpose = 'search';

    /** @return list<float> */
    public function indexing(Closure $callback): array
    {
        $previous = $this->purpose;
        $this->purpose = 'index';
        try {
            return $callback();
        } finally {
            $this->purpose = $previous;
        }
    }
}
