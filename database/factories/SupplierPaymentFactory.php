<?php

namespace Database\Factories;

use App\Models\PurchaseInvoice;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SupplierPayment> */
class SupplierPaymentFactory extends Factory
{
    public function definition(): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'request_hash' => hash('sha256', Str::random()),
            'purchase_invoice_id' => PurchaseInvoice::factory()->state(['status' => 'approved', 'paid_minor' => 1000]),
            'supplier_id' => fn (array $attributes) => PurchaseInvoice::findOrFail($attributes['purchase_invoice_id'])->supplier_id,
            'branch_id' => fn (array $attributes) => PurchaseInvoice::findOrFail($attributes['purchase_invoice_id'])->branch_id,
            'created_by' => fn (array $attributes) => PurchaseInvoice::findOrFail($attributes['purchase_invoice_id'])->created_by,
            'amount_minor' => 1000, 'method' => 'cash', 'payment_date' => now()->toDateString()];
    }
}
