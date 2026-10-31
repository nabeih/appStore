<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\SaleInvoice;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SaleInvoice> */
class SaleInvoiceFactory extends Factory
{
    public function definition(): array
    {
        $uuid = fake()->unique()->uuid();

        return ['client_uuid' => $uuid, 'request_hash' => hash('sha256', $uuid), 'customer_id' => Customer::factory(),
            'branch_id' => BranchFactory::new(), 'created_by' => User::factory(), 'customer_name' => fake()->name(),
            'branch_name' => fake()->company(), 'cashier_name' => fake()->name(), 'invoice_date' => OperationContext::today(),
            'status' => 'draft', 'version' => 1, 'total_quantity' => 0, 'gross_minor' => 0, 'line_discount_minor' => 0,
            'invoice_discount_minor' => 0, 'total_minor' => 0, 'cost_minor' => null, 'payment_instructions' => ['payment_mode' => 'credit', 'payments' => []]];
    }
}
