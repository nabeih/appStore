<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Expense> */
class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return ['branch_id' => Branch::factory(), 'created_by' => User::factory(), 'client_uuid' => (string) Str::uuid(), 'request_hash' => hash('sha256', fake()->uuid()), 'title' => fake()->sentence(3), 'category' => 'other', 'period_start' => OperationContext::today(), 'period_end' => OperationContext::today(), 'transaction_date' => OperationContext::today(), 'amount_minor' => 5000, 'status' => 'draft'];
    }
}
