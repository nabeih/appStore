<?php

namespace App\Http\Controllers\Web\Catalog;

use App\Actions\Catalog\StoreProductColorImagesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductColorImagesRequest;
use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductColorImageController extends Controller
{
    public function index(Product $product): JsonResponse
    {
        return $this->images($product);
    }

    public function store(StoreProductColorImagesRequest $request, Product $product, StoreProductColorImagesAction $store): JsonResponse
    {
        $saved = [];
        try {
            DB::transaction(function () use ($request, $product, $store, &$saved): void {
                DB::table('product_code_counters')->where('id', 1)->lockForUpdate()->first();
                $current = Product::whereKey($product->id)->where('is_active', true)->lockForUpdate()->firstOrFail();
                $saved = $store->execute($current, $request->validated('images'));
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($saved);
            if ($exception instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['images' => 'أُضيفت صورة لهذا اللون في عملية أخرى. حدّث قائمة الصور.']);
            }
            throw $exception;
        }

        return $this->images($product);
    }

    private function images(Product $product): JsonResponse
    {
        return response()->json(['data' => $product->images()->orderBy('color_id')->get()->map(fn ($image): array => ['color_id' => $image->color_id, 'url' => $image->url])]);
    }
}
