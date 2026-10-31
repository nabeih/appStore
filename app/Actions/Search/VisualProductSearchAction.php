<?php

namespace App\Actions\Search;

use App\Contracts\Search\ImageEmbeddingIndex;
use App\Contracts\Search\ImageEmbeddingProvider;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Throwable;

class VisualProductSearchAction
{
    public function __construct(private ImageEmbeddingProvider $provider, private ImageEmbeddingIndex $index, private ProductSearchAction $products) {}

    /** @return list<array<string, mixed>> */
    public function execute(User $actor, UploadedFile $image, int $branchId): array
    {
        Gate::forUser($actor)->authorize('catalog.search');
        Gate::forUser($actor)->authorize('inventory.view');
        OperationContext::branch($actor, $branchId, 'inventory.view');
        abort_unless(config('product_search.visual_enabled') && $this->provider->available() && $this->index->available(), 503, 'البحث البصري غير مفعّل. لم يُثبت نموذج أو مزود معتمد.');
        $details = @getimagesize($image->getPathname());
        abort_unless($image->isValid() && $image->getSize() <= 4 * 1024 * 1024 && $details && $details[0] * $details[1] <= 16_000_000
            && max($details[0], $details[1]) / min($details[0], $details[1]) <= 20
            && in_array($details[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true), 422, 'صورة البحث غير صالحة أو تتجاوز الحدود.');
        $stocks = $this->products->query($actor, ['branch_id' => $branchId])->get();
        $allowed = $stocks->flatMap(fn ($stock) => $stock->productVariant->product->images->where('color_id', $stock->productVariant->color_id))->unique('id')->keyBy('id');
        if ($allowed->isEmpty()) {
            return [];
        }
        try {
            $embedding = $this->provider->embed($image->getPathname());
        } catch (Throwable) {
            abort(503, 'خدمة البحث بالصورة مشغولة أو غير متصلة. أعد المحاولة بعد قليل.');
        }
        abort_if($embedding === [] || count($embedding) > 4096 || collect($embedding)->contains(fn ($value) => ! is_numeric($value) || ! is_finite((float) $value)), 503, 'استجابة مزود الصور غير صالحة.');
        $matches = $this->index->nearest($embedding, $this->provider->version(), $allowed->keys()->all(), 50);
        $ranked = [];
        foreach ($matches as $match) {
            $source = $allowed->get($match['image_id'] ?? null);
            $score = $match['score'] ?? null;
            if (! $source instanceof ProductImage || ! is_numeric($score) || ! is_finite((float) $score)) {
                continue;
            }
            if (! isset($ranked[$source->product_id]) || $ranked[$source->product_id]['score'] < $score) {
                $ranked[$source->product_id] = ['product_id' => $source->product_id, 'score' => (float) $score];
            }
        }

        return collect($ranked)->sortByDesc('score')->take(5)->values()->map(function (array $match) use ($stocks, $actor): array {
            $variants = $stocks->filter(fn ($stock) => $stock->productVariant->product_id === $match['product_id'])
                ->map(fn ($stock) => $this->products->describe($stock, $actor))->values()->all();

            return $match + ['variants' => $variants, 'notice' => 'تشابه محتمل؛ اختر المنتج واللون والمقاس بنفسك.'];
        })->all();
    }
}
