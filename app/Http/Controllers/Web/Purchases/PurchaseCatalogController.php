<?php

namespace App\Http\Controllers\Web\Purchases;

use App\Actions\Catalog\VisibleProductsQuery;
use App\Actions\Purchases\SavePurchaseProductAction;
use App\Actions\Search\ModuleSearch;
use App\Actions\Search\SearchTerm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\StorePurchaseProductRequest;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Support\Sales\OperationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseCatalogController extends Controller
{
    public function variants(Request $request, int $product): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'integer', 'min:1']]);
        OperationContext::branch($request->user(), (int) $data['branch_id'], 'sales.view_cost', false);
        $visible = app(VisibleProductsQuery::class);
        $selected = $visible->execute($request->user())->whereKey($product)->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))->firstOrFail();
        $variants = $visible->variants($request->user())->where('product_id', $selected->id)->where('is_active', true)
            ->whereHas('color', fn ($query) => $query->where('is_active', true))->whereHas('size', fn ($query) => $query->where('is_active', true))
            ->with(['product.images', 'product.swatches', 'color', 'size'])->orderBy('color_id')->orderBy('size_id')->orderBy('id')
            ->paginate(60)->withQueryString();
        $costs = Inventory::where('branch_id', $data['branch_id'])->whereIn('product_variant_id', $variants->getCollection()->modelKeys())
            ->pluck('average_cost', 'product_variant_id');

        return response()->json(['product' => ['product_id' => $selected->id, 'name' => $selected->name, 'code' => $selected->code,
            'category_id' => $selected->category_id], 'data' => $variants->getCollection()->map(fn (ProductVariant $variant): array => self::describe($variant)
                + ['unit_cost' => (string) ($costs[$variant->id] ?? '0.00')]), 'next_page_url' => $variants->nextPageUrl()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function products(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:1', 'max:100'], 'branch_id' => ['nullable', 'integer', 'min:1']]);
        $q = $data['q'];
        if (! empty($data['branch_id'])) {
            OperationContext::branch($request->user(), (int) $data['branch_id'], 'sales.view_cost', false);
        }
        $names = function (Builder $query) use ($q): void {
            foreach (preg_split('/\s+/u', SearchTerm::normalize($q), -1, PREG_SPLIT_NO_EMPTY) as $term) {
                SearchTerm::apply($query, $term, ['name'], ['code']);
            }
        };
        $visible = app(VisibleProductsQuery::class);
        $variants = $visible->variants($request->user())->with(['product.category', 'product.images', 'product.swatches', 'color', 'size'])->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('is_active', true)->whereHas('category', fn ($query) => $query->where('is_active', true)))
            ->whereHas('color', fn ($query) => $query->where('is_active', true))->whereHas('size', fn ($query) => $query->where('is_active', true))
            ->where(fn ($query) => $query->where(fn ($query) => app(ModuleSearch::class)->variants($query, $data))->orWhereHas('product', $names))
            ->orderBy('product_id')->orderBy('id')->limit(30)->get();
        $costs = Inventory::where('branch_id', $data['branch_id'] ?? 0)->whereIn('product_variant_id', $variants->pluck('id'))->pluck('average_cost', 'product_variant_id');
        $products = $visible->execute($request->user())->where('is_active', true)->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->whereDoesntHave('variants')->where($names)->orderBy('name')->limit(20)->get();

        return response()->json(['data' => $variants->map(fn (ProductVariant $variant): array => self::describe($variant) + ['unit_cost' => (string) ($costs[$variant->id] ?? '0.00')]),
            'catalog_products' => $products->map(fn ($product): array => ['product_id' => $product->id, 'name' => $product->name, 'code' => $product->code,
                'category_id' => $product->category_id, 'selling_price' => '0.00'])]);
    }

    public static function describe(ProductVariant $variant): array
    {
        return ['product_variant_id' => $variant->id, 'product_id' => $variant->product_id, 'name' => $variant->product->name, 'code' => $variant->product->code,
            'color_id' => $variant->color_id, 'size_id' => $variant->size_id, 'hex' => $variant->color->display_hex,
            'swatch_url' => $variant->product->swatches->firstWhere('color_id', $variant->color_id)?->url,
            'swatch_manage_url' => route('catalog.swatches.index', $variant->product_id),
            'image_url' => $variant->product->images->firstWhere('color_id', $variant->color_id)?->url,
            'images_url' => route('catalog.product-images.index', $variant->product_id), 'image_upload_url' => route('catalog.product-images.store', $variant->product_id),
            'category_id' => $variant->product->category_id, 'sku' => $variant->sku, 'color' => $variant->color->name, 'size' => $variant->size->name, 'selling_price' => $variant->selling_price];
    }

    public function storeProduct(StorePurchaseProductRequest $request, SavePurchaseProductAction $save): JsonResponse
    {
        $variants = $save->execute($request->validated());

        return response()->json(['message' => 'تم حفظ الأصناف. لا تتغير الكميات إلا باعتماد الفاتورة.', 'data' => array_map(fn (ProductVariant $variant): array => self::describe($variant) + ['unit_cost' => '0.00'], $variants)], 201);
    }

    public function suppliers(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $suppliers = app(ModuleSearch::class)->contacts(Supplier::query(), $data['q'] ?? '', true)->orderBy('name')->limit(20)->get(['id', 'name', 'phone1']);

        return response()->json(['data' => $suppliers]);
    }
}
