<?php

namespace Database\Factories;

use App\Models\DeliveryZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeliveryZone> */
class DeliveryZoneFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->city(), 'fee_minor' => 2000, 'courier_delivery_minor' => 1200, 'courier_refusal_minor' => 700, 'is_active' => true];
    }
}
