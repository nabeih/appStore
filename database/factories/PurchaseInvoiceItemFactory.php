<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\Size;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PurchaseInvoiceItem> */
class PurchaseInvoiceItemFactory extends Factory
{
    public function definition(): array
    {
        return ['purchase_invoice_id' => PurchaseInvoice::factory(), 'product_variant_id' => function (): int {
            $category = Category::firstOrCreate(['name' => 'ملابس']);
            $color = Color::firstOrCreate(['code' => 'BLK'], ['name' => 'أسود']);
            $size = Size::firstOrCreate(['code' => 'L'], ['name' => 'L']);
            $product = Product::create(['name' => 'قميص', 'code' => 'P-'.Str::upper(Str::random(12)), 'category_id' => $category->id]);

            return ProductVariant::create(['product_id' => $product->id, 'color_id' => $color->id, 'size_id' => $size->id, 'sku' => $product->code.'-BLK-L', 'selling_price' => '20.00'])->id;
        }, 'product_name' => 'قميص', 'product_code' => 'SHIRT', 'category_name' => 'ملابس', 'color_name' => 'أسود', 'size_name' => 'L',
            'sku' => fn (array $attributes) => ProductVariant::findOrFail($attributes['product_variant_id'])->sku, 'quantity' => 1, 'unit_cost_minor' => 1000, 'line_total_minor' => 1000];
    }
}
