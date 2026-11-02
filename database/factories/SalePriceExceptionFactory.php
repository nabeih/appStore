<?php

namespace Database\Factories;

use App\Models\SaleInvoice;
use App\Models\SaleItem;
use App\Models\SalePriceException;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalePriceException> */
class SalePriceExceptionFactory extends Factory
{
    public function definition(): array
    {
        return ['sale_invoice_id' => SaleInvoice::factory(), 'sale_item_id' => fn () => SaleItem::query()->firstOrFail()->id, 'user_id' => User::factory(), 'quantity' => 1, 'minimum_unit_minor' => 3000, 'effective_total_minor' => 2500, 'confirmed_at' => now()];
    }
}
