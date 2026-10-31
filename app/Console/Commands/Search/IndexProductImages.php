<?php

namespace App\Console\Commands\Search;

use App\Actions\Search\QueueImageEmbeddingAction;
use App\Models\ProductImage;
use App\Models\ProductImageEmbedding;
use Illuminate\Console\Command;

class IndexProductImages extends Command
{
    protected $signature = 'app:images-index {--chunk=25} {--limit=100} {--retry-pending} {--retry-failed} {--status}';

    protected $description = 'Queue resumable image embedding indexing without running AI in this process';

    public function handle(QueueImageEmbeddingAction $queue): int
    {
        if ($this->option('status')) {
            $this->line(json_encode(ProductImageEmbedding::where('model_version', config('product_search.model_version'))
                ->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->all(), JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        if (! config('product_search.indexing_enabled')) {
            $this->error('فهرسة الصور غير مفعّلة في الإعدادات.');

            return self::FAILURE;
        }
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($chunk === false || $chunk < 1 || $chunk > 200 || $limit === false || $limit < 1 || $limit > 10000) {
            $this->error('chunk must be 1–200; limit must be 1–10000.');

            return self::INVALID;
        }
        $visited = 0;
        $queued = 0;
        $query = ProductImage::query();
        if ($this->option('retry-failed')) {
            $query->whereHas('embeddings', fn ($rows) => $rows->where('model_version', config('product_search.model_version'))->where('status', 'failed'));
        }
        $query->chunkById($chunk, function ($images) use ($queue, $limit, &$visited, &$queued): bool {
            foreach ($images as $image) {
                if ($queued >= $limit) {
                    return false;
                }
                $visited++;
                $queued += (int) $queue->execute($image->id, (bool) ($this->option('retry-pending') || $this->option('retry-failed')));
            }

            return true;
        });
        $this->info('Inspected: '.$visited.'. Queued: '.$queued.'. AI runs only in the ai-indexing worker.');

        return self::SUCCESS;
    }
}
