<?php

namespace Database\Factories;

use App\Models\AppAlert;
use App\Models\AppAlertEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AppAlert> */
class AppAlertFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'app_alert_event_id' => AppAlertEvent::factory()];
    }
}
