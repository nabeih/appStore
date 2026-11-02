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
        $visibleVariants = app(VisibleProductsQuery::class)->variants($request->user(), isset($filters['branch_id']) ? (int) $filters['branch_id'] : null);
        $query = $search->products($this->visibleProducts($request->user()), $filters, $visibleVariants)
            ->with($this->readRelations($request->user(), $filters));
        if (! empty($filters['branch_id'])) {
            $query->whereHas('variants', fn ($variant) => $variant->whereIn('product_variants.id', (clone $visibleVariants)->select('product_variants.id')));
        }

        $search->rankProducts($query, $filters['q'] ?? '', $visibleVariants);
        $products = $search->sort($query, $filters, 'name')->paginate(15)->withQueryString();
        if (! $request->user()->can('sales.view_cost')) {
            $products->each(fn (Product $product) => $product->variants->each->makeHidden('minimum_selling_price'));
        }

        return response()->json($products);
    }

    private function present(Product $product): Product
    {
        $product->load($this->readRelations(auth()->user()));
        if (! auth()->user()->can('sales.view_cost')) {
            $product->variants->each->makeHidden('minimum_selling_price');
        }

        return $product;
    }

    /** @return array<string, mixed> */
    private function readRelations(User $actor, array $filters = []): array
    {
        $visible = app(VisibleProductsQuery::class)->variants($actor, isset($filters['branch_id']) ? (int) $filters['branch_id'] : null);
        $media = fn ($query) => $query->whereIn('color_id', (clone $visible)
            ->whereColumn('product_variants.product_id', $query->getRelated()->getTable().'.product_id')->select('product_variants.color_id'));

        return ['category:id,name', 'images' => $media, 'swatches' => $media,
            'variants' => fn ($query) => app(ModuleSearch::class)->variants($query, $filters)
                ->whereIn('product_variants.id', (clone $visible)->select('product_variants.id')),
            'variants.color:id,name,code', 'variants.size:id,name,code'];
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
        $product = $this->visibleProducts(auth()->user())->findOrFail($id);

        return response()->json($this->present($product));
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
