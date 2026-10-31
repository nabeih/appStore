<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->name(), 'phone' => fake()->unique()->numerify('059#######'), 'address' => fake()->address()];
    }
}
