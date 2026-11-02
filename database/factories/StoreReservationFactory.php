<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\StoreOrder;
use App\Models\StoreReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreReservation> */
class StoreReservationFactory extends Factory
{
    public function definition(): array
    {
        return ['store_order_id' => StoreOrder::factory(), 'branch_id' => BranchFactory::new(), 'product_variant_id' => fn () => ProductVariant::query()->firstOrFail()->id, 'quantity' => 1];
    }
}
