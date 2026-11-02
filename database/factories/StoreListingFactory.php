<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StoreListing;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreListing> */
class StoreListingFactory extends Factory
{
    public function definition(): array
    {
        return ['product_id' => fn () => Product::query()->firstOrFail()->id, 'slug' => fake()->unique()->slug(), 'is_published' => true];
    }
}
