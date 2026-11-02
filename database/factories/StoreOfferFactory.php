<?php

namespace Database\Factories;

use App\Models\StoreOffer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreOffer> */
class StoreOfferFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->sentence(3), 'type' => 'delivery', 'minimum_minor' => 20000, 'minimum_inclusive' => true, 'percent_basis_points' => 0, 'is_active' => true];
    }
}
