<?php

namespace Database\Factories;

use App\Models\StoreBanner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreBanner> */
class StoreBannerFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => fake()->sentence(3), 'is_active' => true];
    }
}
