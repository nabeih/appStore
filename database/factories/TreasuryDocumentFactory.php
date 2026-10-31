<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\TreasuryDocument;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TreasuryDocument> */
class TreasuryDocumentFactory extends Factory
{
    public function definition(): array
    {
        return ['branch_id' => Branch::factory(), 'to_account_id' => fn (array $attributes) => FinancialAccount::factory()->create(['branch_id' => $attributes['branch_id']])->id, 'created_by' => User::factory(), 'client_uuid' => (string) Str::uuid(), 'request_hash' => hash('sha256', fake()->uuid()), 'transaction_date' => OperationContext::today(), 'kind' => 'owner_contribution', 'amount_minor' => 1000, 'reason' => 'حركة اختبار', 'status' => 'approved'];
    }
}
