<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Supplier> */
class SupplierFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->company(), 'phone1' => fake()->numerify('059#######'), 'address' => fake()->streetAddress()];
    }
}
