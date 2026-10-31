<?php

namespace App\Observers\Search;

use App\Actions\Search\QueueImageEmbeddingAction;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProductImageEmbeddingObserver
{
    public function saved(ProductImage $image): void
    {
        if (! config('product_search.indexing_enabled') || (! $image->wasRecentlyCreated && ! $image->wasChanged('image_path'))) {
            return;
        }
        $id = $image->id;
        DB::afterCommit(function () use ($id): void {
            try {
                app(QueueImageEmbeddingAction::class)->execute($id);
            } catch (Throwable) {
                Log::warning('visual_index_dispatch_failed', ['product_image_id' => $id]);
            }
        });
    }
}
