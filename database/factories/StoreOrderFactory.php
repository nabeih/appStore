<?php

namespace Database\Factories;

use App\Actions\Store\StoreOrderFinance;
use App\Models\DeliveryZone;
use App\Models\StoreOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StoreOrder> */
class StoreOrderFactory extends Factory
{
    public function definition(): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'sale_uuid' => (string) Str::uuid(), 'request_hash' => hash('sha256', Str::random(40)), 'branch_id' => BranchFactory::new(), 'delivery_zone_id' => DeliveryZone::factory(), 'customer_name' => fake()->name(), 'phone' => fake()->numerify('059#######'), 'address' => fake()->address(), 'products_minor' => 3500, 'original_delivery_minor' => 2000, 'delivery_minor' => 2000, 'delivery_snapshot' => ['fee_policy' => StoreOrderFinance::POLICY, 'zone' => 'اختبار', 'courier_delivery_minor' => 1200, 'courier_refusal_minor' => 700], 'accepted_policy' => 'سياسة طلب اختبار موثقة', 'policy_version' => hash('sha256', 'test'), 'policy_accepted_at' => now()];
    }
}
