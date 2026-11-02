<?php

namespace Database\Factories;

use App\Models\MobileDevice;
use App\Models\MobileOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MobileOperation> */
class MobileOperationFactory extends Factory
{
    public function definition(): array
    {
        return ['device_id' => MobileDevice::factory(), 'operation_uuid' => fake()->uuid(), 'branch_id' => BranchFactory::new(),
            'operation_type' => 'product.create', 'request_hash' => hash('sha256', fake()->uuid()), 'payload' => ['name' => fake()->word()],
            'dependencies' => [], 'client_created_at' => now(), 'status' => 'validation_failed', 'result' => ['code' => 'validation_failed']];
    }
}
