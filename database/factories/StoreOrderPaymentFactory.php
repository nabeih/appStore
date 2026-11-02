<?php

namespace Database\Factories;

use App\Models\StoreOrder;
use App\Models\StoreOrderPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StoreOrderPayment> */
class StoreOrderPaymentFactory extends Factory
{
    public function definition(): array
    {
        return ['store_order_id' => StoreOrder::factory(), 'user_id' => User::factory(), 'operation_uuid' => (string) Str::uuid(), 'request_hash' => hash('sha256', Str::random(40)), 'kind' => 'collect', 'method' => 'cash', 'received_by' => 'courier', 'amount_minor' => 1000];
    }
}
