<?php

namespace Database\Seeders;

use App\Actions\Finance\ManageFinancialAccountsAction;
use Illuminate\Database\Seeder;

class FinancialAccountSeeder extends Seeder
{
    public function run(): void
    {
        ManageFinancialAccountsAction::provision();
    }
}
