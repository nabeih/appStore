<?php

namespace App\Actions\Catalog;

use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateProductAction
{
    public function execute(array $data): Product
    {
        $savedFiles = [];
        try {
            return DB::transaction(function () use ($data, &$savedFiles): Product {
                DB::table('product_code_counters')->where('id', 1)->increment('lock_version');
                $variants = $data['variants'];
                $product = Product::create(collect($data)->except(['variants', 'images', 'image_path'])->all());
                $colors = Color::whereIn('id', collect($variants)->pluck('color_id'))->get()->keyBy('id');
                $sizes = Size::whereIn('id', collect($variants)->pluck('size_id'))->get()->keyBy('id');
                foreach ($variants as $variant) {
                    $color = $colors->get($variant['color_id']);
                    $size = $sizes->get($variant['size_id']);
                    if (! $color || ! $size || blank($color->code) || blank($size->code)) {
                        throw ValidationException::withMessages(['variants' => 'اختر لونًا ومقاسًا موجودين مع رمز لكل منهما.']);
                    }
                    $product->variants()->create(['color_id' => $color->id, 'size_id' => $size->id,
                        'sku' => strtoupper($product->code.'-'.$color->code.'-'.$size->code),
                        'selling_price' => $variant['selling_price'], 'minimum_selling_price' => $variant['minimum_selling_price'] ?? null, 'is_active' => true]);
                }
                $savedFiles = app(StoreProductColorImagesAction::class)->execute($product, $data['images'] ?? []);

                return $product->load(['category', 'variants.color', 'variants.size', 'images.color', 'swatches']);
            });
        } catch (Throwable $error) {
            Storage::disk('public')->delete($savedFiles);
            throw $error;
        }
    }
}
