<?php

namespace App\Jobs\Search;

use App\Actions\Search\IndexProductImageEmbeddingAction;
use App\Models\ProductImageEmbedding;
use App\Support\Search\PreventConcurrentImageIndexing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class IndexProductImageEmbeddingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public int $embeddingId, public string $generation)
    {
        $this->onConnection('ai_database')->onQueue('ai-indexing');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 180];
    }

    /** @return list<PreventConcurrentImageIndexing> */
    public function middleware(): array
    {
        return [new PreventConcurrentImageIndexing];
    }

    public function handle(IndexProductImageEmbeddingAction $index): void
    {
        try {
            $index->execute($this->embeddingId, $this->generation);
        } catch (Throwable $error) {
            ProductImageEmbedding::whereKey($this->embeddingId)->where('generation', $this->generation)
                ->where('status', '!=', 'ready')->update(['status' => 'pending', 'last_error' => 'تعذر التحليل مؤقتًا؛ ستتم إعادة المحاولة.']);
            throw $error;
        }
    }

    public function failed(?Throwable $exception): void
    {
        ProductImageEmbedding::whereKey($this->embeddingId)->where('generation', $this->generation)
            ->where('status', '!=', 'ready')->update(['status' => 'failed', 'last_error' => 'فشلت محاولات الفهرسة؛ تحقق من الخدمة وملف الصورة ثم أعد المحاولة.']);
    }
}
