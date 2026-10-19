<?php


namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use App\Actions\Catalog\CreateProductAction;
use App\Actions\Catalog\UpdateProductAction;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;


class ProductController extends Controller
{
    public function index()
    {
        $products = Product::query()
            ->with([
                'category:id,name',
                'variants.color:id,name,code',
                'variants.size:id,name,code',
            ])
            ->latest()
            ->paginate(15);

        return response()->json($products);
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
            'data' => $product,
        ], 201);
    }

    function show($id)
    {
        $product = Product::with([
            'category:id,name',
            'variants.color:id,name,code',
            'variants.size:id,name,code',
        ])->findOrFail($id);

        return response()->json($product);
    }

    public function update(
        UpdateProductRequest $request,
        int $id,
        UpdateProductAction $updateProduct
    ): JsonResponse {

        $product = Product::findOrFail($id);

        $updatedProduct = $updateProduct->execute(
            $product,
            $request->validated()
        );

        return response()->json([
            'message' => 'تم تعديل المنتج بنجاح',
            'data' => $updatedProduct,
        ]);
    }

    public function destroy($id)
    {
        $product = Product::findOrfail($id);
        $product->delete();

        return response()->json([
            'message' => 'تم حذف المنتج بنجاح',
        ]);
    }
}
