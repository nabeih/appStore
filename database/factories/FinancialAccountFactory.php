<?php

namespace Database\Factories;

use App\Models\FinancialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinancialAccount> */
class FinancialAccountFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'درج '.fake()->unique()->word(), 'code' => 'TEST-'.fake()->unique()->uuid(),
            'type' => 'cash', 'branch_id' => BranchFactory::new(), 'is_active' => true];
    }
}
