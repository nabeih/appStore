<?php

namespace Database\Seeders;

use App\Models\CashCount;
use Illuminate\Database\Seeder;

class CashCountSeeder extends Seeder
{
    /** Creates raw fixtures only in an explicitly selected testing environment, without financial posting. */
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Operational financial documents must not be seeded into customer data.');
        }
        CashCount::factory()->create();
    }
}
