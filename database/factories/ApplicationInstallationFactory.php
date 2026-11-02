<?php

namespace Database\Factories;

use App\Models\ApplicationInstallation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ApplicationInstallation> */
class ApplicationInstallationFactory extends Factory
{
    protected $model = ApplicationInstallation::class;

    public function definition(): array
    {
        return ['id' => 1, 'status' => 'unprepared'];
    }
}
