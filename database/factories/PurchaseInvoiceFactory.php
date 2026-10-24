<?php

namespace Database\Factories;

use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @extends Factory<PurchaseInvoice> */
class PurchaseInvoiceFactory extends Factory
{
    public function definition(): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'request_hash' => hash('sha256', Str::random()), 'supplier_id' => Supplier::factory(),
            'branch_id' => fn () => DB::table('branches')->insertGetId(['name' => fake()->unique()->company()]), 'created_by' => User::factory(),
            'supplier_name' => fn (array $attributes) => Supplier::withTrashed()->findOrFail($attributes['supplier_id'])->name,
            'branch_name' => fn (array $attributes) => DB::table('branches')->where('id', $attributes['branch_id'])->value('name'),
            'invoice_date' => now()->toDateString(), 'status' => 'draft', 'total_quantity' => 1, 'total_minor' => 1000, 'paid_minor' => 0];
    }
}
