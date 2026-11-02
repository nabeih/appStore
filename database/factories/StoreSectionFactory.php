<?php

namespace Database\Factories;

use App\Models\StoreSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreSection> */
class StoreSectionFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->words(2, true), 'slug' => fake()->unique()->slug(), 'is_active' => true];
    }
}
