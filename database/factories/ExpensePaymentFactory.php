<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ExpensePayment> */
class ExpensePaymentFactory extends Factory
{
    public function definition(): array
    {
        return ['expense_id' => Expense::factory(), 'branch_id' => fn (array $attributes) => Expense::findOrFail($attributes['expense_id'])->branch_id, 'financial_account_id' => fn (array $attributes) => FinancialAccount::factory()->create(['branch_id' => $attributes['branch_id']])->id, 'created_by' => User::factory(), 'client_uuid' => (string) Str::uuid(), 'request_hash' => hash('sha256', fake()->uuid()), 'transaction_date' => OperationContext::today(), 'amount_minor' => 1000, 'reason' => 'سند اختبار', 'status' => 'approved'];
    }
}
