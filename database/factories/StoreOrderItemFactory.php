<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreOrderItem> */
class StoreOrderItemFactory extends Factory
{
    public function definition(): array
    {
        return ['store_order_id' => StoreOrder::factory(), 'product_variant_id' => fn () => ProductVariant::query()->firstOrFail()->id, 'product_name' => 'منتج اختبار', 'sku' => fake()->unique()->bothify('IT-########'), 'color_name' => 'أسود', 'size_name' => 'M', 'quantity' => 1, 'base_price_minor' => 3500, 'unit_price_minor' => 3500, 'net_minor' => 3500];
    }
}
