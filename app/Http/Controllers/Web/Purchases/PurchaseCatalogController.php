<?php

namespace App\Http\Controllers\Web\Purchases;

use App\Actions\Purchases\SavePurchaseProductAction;
use App\Actions\Search\ModuleSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\StorePurchaseProductRequest;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Support\Sales\OperationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseCatalogController extends Controller
{
    public function products(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:1', 'max:100'], 'branch_id' => ['nullable', 'integer', 'min:1']]);
        $q = $data['q'];
        $variants = ProductVariant::with(['product.category', 'product.images', 'product.swatches', 'color', 'size'])->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('is_active', true)->whereHas('category', fn ($query) => $query->where('is_active', true)))
            ->whereHas('color', fn ($query) => $query->where('is_active', true))->whereHas('size', fn ($query) => $query->where('is_active', true))
            ->where(fn ($query) => app(ModuleSearch::class)->variants($query, $data))
            ->orderBy('product_id')->orderBy('id')->limit(30)->get();
        if (! empty($data['branch_id'])) {
            OperationContext::branch($request->user(), (int) $data['branch_id'], 'sales.view_cost', false);
        }
        $costs = Inventory::where('branch_id', $data['branch_id'] ?? 0)->whereIn('product_variant_id', $variants->pluck('id'))->pluck('average_cost', 'product_variant_id');

        return response()->json(['data' => $variants->map(fn (ProductVariant $variant): array => self::describe($variant) + ['unit_cost' => (string) ($costs[$variant->id] ?? '0.00')])]);
    }

    public static function describe(ProductVariant $variant): array
    {
        return ['product_variant_id' => $variant->id, 'product_id' => $variant->product_id, 'name' => $variant->product->name, 'code' => $variant->product->code,
            'color_id' => $variant->color_id, 'hex' => $variant->color->display_hex,
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
