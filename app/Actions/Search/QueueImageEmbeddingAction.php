<?php

namespace App\Actions\Search;

use App\Jobs\Search\IndexProductImageEmbeddingJob;
use App\Models\ProductImage;
use App\Models\ProductImageEmbedding;
use App\Support\Search\LocalProductImagePath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class QueueImageEmbeddingAction
{
    public function __construct(private LocalProductImagePath $paths) {}

    public function execute(int $imageId, bool $retry = false): bool
    {
        if (! config('product_search.indexing_enabled')) {
            return false;
        }
        $image = ProductImage::find($imageId);
        if (! $image) {
            return false;
        }
        $path = $image->image_path;
        $missing = false;
        try {
            $hash = hash_file('sha256', $this->paths->resolve($path));
        } catch (RuntimeException) {
            $hash = str_repeat('0', 64);
            $missing = true;
        }

        return DB::transaction(function () use ($imageId, $path, $hash, $missing, $retry): bool {
            $current = ProductImage::whereKey($imageId)->lockForUpdate()->first();
            if (! $current || $current->image_path !== $path) {
                return false;
            }
            $record = ProductImageEmbedding::where('product_image_id', $imageId)
                ->where('model_version', config('product_search.model_version'))->lockForUpdate()->first();
            $unchanged = $record && $record->image_path === $path && hash_equals($record->image_hash, $hash);
            if ($unchanged && ($record->status === 'ready' || (! $retry && in_array($record->status, ['pending', 'processing'], true)))) {
                return false;
            }
            $record ??= new ProductImageEmbedding(['product_image_id' => $imageId, 'model_version' => config('product_search.model_version')]);
            $record->fill(['image_path' => $path, 'image_hash' => $hash, 'generation' => (string) Str::ulid(),
                'dimensions' => null, 'embedding' => null, 'indexed_at' => null,
                'status' => $missing ? 'failed' : 'pending', 'last_error' => $missing ? 'ملف الصورة غير متاح؛ أصلح مصدر الصورة ثم أعد المحاولة.' : null])->save();
            if (! $missing) {
                /** The source image has committed. Persist metadata and its database job atomically. */
                IndexProductImageEmbeddingJob::dispatch($record->id, $record->generation)->beforeCommit();
            }

            return ! $missing;
        }, 3);
    }
}
