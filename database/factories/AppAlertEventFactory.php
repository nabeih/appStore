<?php

namespace Database\Factories;

use App\Models\AppAlertEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AppAlertEvent> */
class AppAlertEventFactory extends Factory
{
    public function definition(): array
    {
        return ['event_key' => fake()->unique()->uuid(), 'type' => 'store_order', 'branch_id' => BranchFactory::new(), 'subject_id' => 1, 'title' => 'طلب جديد', 'message' => 'تنبيه اختبار'];
    }
}
