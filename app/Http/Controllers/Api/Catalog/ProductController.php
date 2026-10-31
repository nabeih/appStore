<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Actions\Catalog\CreateProductAction;
use App\Actions\Catalog\UpdateProductAction;
use App\Actions\Catalog\VisibleProductsQuery;
use App\Actions\Search\ModuleSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Requests\Search\SearchRequest;
use App\Models\OpeningStockItem;
use App\Models\Product;
use App\Models\PurchaseInvoiceItem;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ProductController extends Controller
{
    public function index(SearchRequest $request, ModuleSearch $search): JsonResponse
    {
        $filters = $request->validated();
        Gate::authorize('catalog.search');
        $query = $search->products($this->visibleProducts($request->user()), $filters)->with([
            'category:id,name', 'images', 'swatches', 'variants' => fn ($query) => $search->variants($query, $filters),
            'variants.color:id,name,code', 'variants.size:id,name,code',
        ]);

        $search->rankProducts($query, $filters['q'] ?? '');
        $products = $search->sort($query, $filters, 'name')->paginate(15)->withQueryString();
        if (! $request->user()->can('sales.view_cost')) {
            $products->each(fn (Product $product) => $product->variants->each->makeHidden('minimum_selling_price'));
        }

        return response()->json($products);
    }

    private function present(Product $product): Product
    {
        if (! auth()->user()->can('sales.view_cost')) {
            $product->variants->each->makeHidden('minimum_selling_price');
        }

        return $product;
    }

    private function visibleProducts(User $actor): Builder
    {
        return app(VisibleProductsQuery::class)->execute($actor);
    }

    public function store(
        StoreProductRequest $request,
        CreateProductAction $createProduct
    ): JsonResponse {
        $product = $createProduct->execute(
            $request->validated()
        );

        return response()->json([
            'message' => 'تمت إضافة المنتج بنجاح',
            'appearance_url' => route('catalog.swatches.edit', $product),
            'data' => $this->present($product),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        Gate::authorize('catalog.search');
        $product = $this->visibleProducts(auth()->user())->with([
            'category:id,name',
            'images', 'swatches',
            'variants.color:id,name,code',
            'variants.size:id,name,code',
        ])->findOrFail($id);

        if (! auth()->user()->can('sales.view_cost')) {
            $product->variants->each->makeHidden('minimum_selling_price');
        }

        return response()->json($product);
    }

    public function update(
        UpdateProductRequest $request,
        int $id,
        UpdateProductAction $updateProduct
    ): JsonResponse {

        $product = $this->visibleProducts($request->user())->findOrFail($id);

        $updatedProduct = $updateProduct->execute(
            $product,
            $request->validated()
        );

        return response()->json([
            'message' => 'تم تعديل المنتج بنجاح',
            'data' => $this->present($updatedProduct),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        Gate::authorize('inventory.manage_catalog');
        $message = OperationContext::run(function () use ($id): string {
            $product = $this->visibleProducts(auth()->user())->whereKey($id)->lockForUpdate()->firstOrFail();
            $ids = $product->variants()->pluck('id');
            $documented = StockMovement::whereIn('product_variant_id', $ids)->exists()
                || OpeningStockItem::whereIn('product_variant_id', $ids)->exists()
                || PurchaseInvoiceItem::whereIn('product_variant_id', $ids)->exists()
                || SaleItem::whereIn('product_variant_id', $ids)->exists();
            if ($documented) {
                $product->update(['is_active' => false]);
                OperationContext::audit($product, auth()->user(), 'product.disabled');

                return 'عُطل المنتج مع الاحتفاظ بمستنداته ومخزونه.';
            }
            $product->delete();

            return 'أُرشف المنتج غير المرتبط بمستندات.';
        });

        return response()->json(['message' => $message]);
    }
}
