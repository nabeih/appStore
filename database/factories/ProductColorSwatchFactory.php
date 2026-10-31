<?php

namespace Database\Factories;

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductColorSwatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductColorSwatch> */
class ProductColorSwatchFactory extends Factory
{
    public function definition(): array
    {
        return ['product_id' => fn (): int => Product::query()->firstOrFail()->id, 'color_id' => fn (): int => Color::query()->firstOrFail()->id,
            'image_path' => 'products/swatches/'.fake()->uuid().'.webp'];
    }
}
