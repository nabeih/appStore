<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CashCount;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CashCount> */
class CashCountFactory extends Factory
{
    public function definition(): array
    {
        return ['branch_id' => Branch::factory(), 'financial_account_id' => fn (array $attributes) => FinancialAccount::factory()->create(['branch_id' => $attributes['branch_id']])->id, 'created_by' => User::factory(), 'client_uuid' => (string) Str::uuid(), 'request_hash' => hash('sha256', fake()->uuid()), 'transaction_date' => OperationContext::today(), 'expected_minor' => 0, 'actual_minor' => 0, 'difference_minor' => 0, 'reason' => 'جرد اختبار'];
    }
}
