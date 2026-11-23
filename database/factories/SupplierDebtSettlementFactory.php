<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Supplier;
use App\Models\SupplierDebtSettlement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SupplierDebtSettlement>
 */
class SupplierDebtSettlementFactory extends Factory
{
    public function withoutEvidence(): static
    {
        return $this->state(fn (array $attributes): array => ['evidence_path' => null, 'evidence_name' => null, 'evidence_sha256' => null]);
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'request_hash' => hash('sha256', Str::random()),
            'origin_fingerprint' => hash('sha256', Str::random()), 'supplier_id' => Supplier::factory(), 'branch_id' => Branch::factory(), 'created_by' => User::factory(),
            'original_date' => now()->subYear()->toDateString(), 'tracking_started_on' => now()->toDateString(), 'posting_date' => now()->toDateString(),
            'amount_minor' => 500000, 'reference' => fake()->unique()->bothify('OPEN-########'), 'notes' => 'التزام افتتاحي غير مسجل مع دليل مستقل.',
            'evidence_path' => 'supplier-debt-evidence/'.Str::uuid().'.pdf', 'evidence_name' => 'statement.pdf', 'evidence_sha256' => hash('sha256', 'test')];
    }
}
