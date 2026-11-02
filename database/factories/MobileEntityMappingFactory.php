<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\MobileDevice;
use App\Models\MobileEntityMapping;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MobileEntityMapping> */
class MobileEntityMappingFactory extends Factory
{
    public function definition(): array
    {
        return ['device_id' => MobileDevice::factory(), 'entity' => 'products', 'client_uuid' => fake()->uuid(),
            'server_id' => fn (): int => Product::create(['name' => fake()->word(), 'code' => strtoupper(fake()->unique()->bothify('TEST-########')),
                'category_id' => Category::firstOrCreate(['name' => 'Factory category'])->id, 'is_active' => true])->id];
    }
}
