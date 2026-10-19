<?php

namespace App\Actions\Catalog;

use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateProductAction
{
    public function execute(array $data): Product
    {
        return DB::transaction(function () use ($data) {

            $variants = $data['variants'];
            $images = $data['images'] ?? [];

            // نفصل بيانات المنتج عن بيانات العلاقات.
            $productData = collect($data)
                ->except(['variants', 'images'])
                ->all();

            $product = Product::create($productData);

            // تحميل الأكواد دفعة واحدة لتجنب N+1 Queries.
            $colors = Color::query()
                ->whereIn('id', collect($variants)->pluck('color_id'))
                ->get()
                ->keyBy('id');

            $sizes = Size::query()
                ->whereIn('id', collect($variants)->pluck('size_id'))
                ->get()
                ->keyBy('id');

            foreach ($variants as $variant) {
                $color = $colors->get($variant['color_id']);
                $size = $sizes->get($variant['size_id']);

                if (! $color || ! $size) {
                    throw ValidationException::withMessages([
                        'variants' => 'اللون أو المقاس المحدد غير موجود.',
                    ]);
                }

                if (blank($color->code) || blank($size->code)) {
                    throw ValidationException::withMessages([
                        'variants' => 'يجب تحديد كود لكل لون ومقاس لتوليد SKU.',
                    ]);
                }

                $sku = strtoupper(
                    $product->code . '-' . $color->code . '-' . $size->code
                );

                $product->variants()->create([
                    'color_id' => $color->id,
                    'size_id' => $size->id,
                    'sku' => $sku,
                    'selling_price' => $variant['selling_price'],
                    'minimum_selling_price' =>
                    $variant['minimum_selling_price'] ?? null,
                    'is_active' => true,
                ]);
            }

            foreach ($images as $image) {
                $product->images()->create([
                    'color_id' => $image['color_id'],
                    'image_path' => $image['image_path'],
                ]);
            }

            return $product->load([
                'category',
                'variants.color',
                'variants.size',
                'images.color',
            ]);
        });
    }
}
