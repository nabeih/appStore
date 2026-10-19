<?php

namespace App\Actions\Catalog;

use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateProductAction
{
    public function execute(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {

            // تعديل بيانات المنتج الأساسية
            $product->update(
                collect($data)->except('variants')->all()
            );
            // إذا تغير كود المنتج، نحدث SKU لجميع الـVariants التابعة له
            if ($product->wasChanged('code')) {

                $product->load('variants.color', 'variants.size');

                foreach ($product->variants as $variant) {

                    $variant->update([
                        'sku' => strtoupper(
                            $product->code . '-'
                                . $variant->color->code . '-'
                                . $variant->size->code
                        ),
                    ]);
                }
            }

            foreach ($data['variants'] ?? [] as $item) {

                $color = Color::findOrFail($item['color_id']);
                $size = Size::findOrFail($item['size_id']);

                if (blank($color->code) || blank($size->code)) {
                    throw ValidationException::withMessages([
                        'variants' => 'يجب أن يكون لكل لون ومقاس كود.',
                    ]);
                }

                $sku = strtoupper(
                    $product->code . '-' . $color->code . '-' . $size->code
                );

                // Variant موجود: نعدّله
                if (!empty($item['id'])) {

                    $variant = $product->variants()
                        ->findOrFail($item['id']);

                    $variant->update([
                        'color_id' => $color->id,
                        'size_id' => $size->id,
                        'sku' => $sku,
                        'selling_price' => $item['selling_price'],
                        'minimum_selling_price' =>
                        $item['minimum_selling_price'] ?? null,
                        'is_active' =>
                        $item['is_active'] ?? $variant->is_active,
                    ]);
                } else {

                    // Variant جديد: ننشئه
                    $product->variants()->create([
                        'color_id' => $color->id,
                        'size_id' => $size->id,
                        'sku' => $sku,
                        'selling_price' => $item['selling_price'],
                        'minimum_selling_price' =>
                        $item['minimum_selling_price'] ?? null,
                        'is_active' => $item['is_active'] ?? true,
                    ]);
                }
            }

            return $product->fresh()->load([
                'category',
                'variants.color',
                'variants.size',
                'images.color',
            ]);
        });
    }
}
