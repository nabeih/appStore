<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class StoreProductColorImagesAction
{
    /**
     * The caller holds the product lock and owns the database transaction.
     *
     * @param  list<array{color_id: int|string, image: UploadedFile}>  $images
     * @return list<string> Paths to remove if the caller subsequently rolls back.
     */
    public function execute(Product $product, array $images): array
    {
        if ($images === []) {
            return [];
        }
        $colorIds = array_map(fn (array $image): int => (int) $image['color_id'], $images);
        $allowed = $product->variants()->whereIn('color_id', $colorIds)->where('is_active', true)
            ->whereHas('color', fn ($query) => $query->where('is_active', true))->distinct()->pluck('color_id')->map(fn ($id): int => (int) $id)->all();
        $existing = $product->images()->whereIn('color_id', $colorIds)->pluck('color_id')->map(fn ($id): int => (int) $id)->all();
        $seen = [];
        foreach ($colorIds as $index => $colorId) {
            if (isset($seen[$colorId]) || in_array($colorId, $existing, true)) {
                throw ValidationException::withMessages(["images.$index.image" => 'لهذا المنتج واللون صورة واحدة فقط. استخدم الصورة المحفوظة لجميع المقاسات.']);
            }
            if (! in_array($colorId, $allowed, true)) {
                throw ValidationException::withMessages(["images.$index.color_id" => 'اللون غير فعال أو لا ينتمي إلى أصناف هذا المنتج.']);
            }
            $seen[$colorId] = true;
        }
        $saved = [];
        try {
            foreach ($images as $image) {
                $paths = app(ProcessProductImageAction::class)->upload($image['image']);
                array_push($saved, ...array_values($paths));
                ProductImage::create(['product_id' => $product->id, 'color_id' => (int) $image['color_id']] + $paths);
            }

            return $saved;
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($saved);
            throw $exception;
        }
    }
}
