<?php

namespace Database\Seeders;

use App\Actions\Access\InitializeAccessAction;
use Illuminate\Database\Seeder;

class AccessRolesSeeder extends Seeder
{
    public function run(): void
    {
        app(InitializeAccessAction::class)->execute();
    }
}
