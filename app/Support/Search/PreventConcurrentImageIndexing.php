<?php

namespace App\Support\Search;

use App\Jobs\Search\IndexProductImageEmbeddingJob;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PreventConcurrentImageIndexing
{
    public function lockKey(IndexProductImageEmbeddingJob $job): string
    {
        return 'visual-image-index:'.hash('sha256', DB::connection()->getDatabaseName()).':'.$job->embeddingId;
    }

    public function handle(IndexProductImageEmbeddingJob $job, Closure $next): void
    {
        $lock = Cache::store('file')->lock($this->lockKey($job), 90);
        if (! $lock->get()) {
            $job->release(5);

            return;
        }
        try {
            $next($job);
        } finally {
            $lock->release();
        }
    }
}
