<?php

namespace Database\Factories;

use App\Models\StoreOrder;
use App\Models\StoreOrderEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StoreOrderEvent> */
class StoreOrderEventFactory extends Factory
{
    public function definition(): array
    {
        return ['store_order_id' => StoreOrder::factory(), 'operation_uuid' => (string) Str::uuid(), 'request_hash' => hash('sha256', Str::random(40)), 'to_status' => 'new'];
    }
}
