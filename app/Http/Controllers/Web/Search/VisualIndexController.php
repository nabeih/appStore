<?php

namespace App\Http\Controllers\Web\Search;

use App\Actions\Search\LocalImageEmbeddingProvider;
use App\Actions\Search\ProductSearchAction;
use App\Actions\Search\QueueImageEmbeddingAction;
use App\Http\Controllers\Controller;
use App\Models\ProductImageEmbedding;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VisualIndexController extends Controller
{
    /** @return list<int> */
    private function allowedImages(User $actor, ProductSearchAction $search): array
    {
        return $search->query($actor, [])->get()->flatMap(fn ($stock) => $stock->productVariant->product->images
            ->where('color_id', $stock->productVariant->color_id))->pluck('id')->unique()->values()->all();
    }

    public function index(Request $request, ProductSearchAction $search, LocalImageEmbeddingProvider $provider): View
    {
        $ids = $this->allowedImages($request->user(), $search);
        $base = ProductImageEmbedding::where('model_version', config('product_search.model_version'))->whereIn('product_image_id', $ids);
        $counts = (clone $base)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $missing = count($ids) - $counts->sum();
        $rows = (clone $base)->with('image.product', 'image.color')->orderByDesc('updated_at')->paginate(20);
        $health = $provider->health();

        return view('search.visual.index', compact('counts', 'missing', 'rows', 'health'));
    }

    public function retry(Request $request, int $embedding, ProductSearchAction $search, QueueImageEmbeddingAction $queue): RedirectResponse
    {
        $record = ProductImageEmbedding::whereKey($embedding)->where('model_version', config('product_search.model_version'))
            ->whereIn('product_image_id', $this->allowedImages($request->user(), $search))->firstOrFail();
        $queue->execute($record->product_image_id, true);

        return to_route('search.visual-index')->with('success', 'تمت جدولة الصورة؛ تعالجها قائمة الفهرسة في الخلفية.');
    }
}
