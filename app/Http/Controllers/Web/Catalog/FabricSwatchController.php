<?php

namespace App\Http\Controllers\Web\Catalog;

use App\Actions\Catalog\ManageFabricSwatchAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\FabricSwatchRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class FabricSwatchController extends Controller
{
    public function index(Product $product): JsonResponse
    {
        return response()->json($this->describe($product));
    }

    public function edit(Product $product): View
    {
        return view('catalog.swatches', ['product' => $product, 'appearance' => $this->describe($product)]);
    }

    public function store(FabricSwatchRequest $request, Product $product, int $color, ManageFabricSwatchAction $action): JsonResponse
    {
        $action->save($product, $color, $request->validated(), $request->user());

        return response()->json(['message' => 'حُفظت عينة القماش لجميع مقاسات هذا اللون.'] + $this->describe($product));
    }

    public function destroy(FabricSwatchRequest $request, Product $product, int $color, ManageFabricSwatchAction $action): JsonResponse
    {
        $action->delete($product, $color, $request->validated('revision'), $request->user());

        return response()->json(['message' => 'حُذفت العينة؛ سيُعرض اللون البديل.'] + $this->describe($product));
    }

    private function describe(Product $product): array
    {
        $product->load(['images', 'swatches']);
        $variants = $product->variants()->where('is_active', true)->whereHas('color', fn ($query) => $query->where('is_active', true))->with('color')->get()->unique('color_id');

        return ['product_id' => $product->id, 'name' => $product->name,
            'manage_url' => route('catalog.swatches.index', $product),
            'colors' => $variants->map(function ($variant) use ($product): array {
                $source = $product->images->firstWhere('color_id', $variant->color_id);
                $swatch = $product->swatches->firstWhere('color_id', $variant->color_id);

                return ['color_id' => $variant->color_id, 'name' => $variant->color->name, 'hex' => $variant->color->display_hex,
                    'swatch' => $swatch ? ['url' => $swatch->url, 'revision' => $swatch->revision] : null,
                    'source_image' => $source ? ['id' => $source->id, 'url' => $source->optimized_url, 'revision' => ManageFabricSwatchAction::sourceRevision($source)] : null,
                    'save_url' => route('catalog.swatches.store', [$product, $variant->color_id])];
            })->values()->all()];
    }
}
