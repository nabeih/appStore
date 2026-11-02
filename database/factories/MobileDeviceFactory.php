<?php

namespace Database\Factories;

use App\Actions\Mobile\MobileAccess;
use App\Models\MobileDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MobileDevice> */
class MobileDeviceFactory extends Factory
{
    public function definition(): array
    {
        return ['id' => fake()->uuid(), 'user_id' => User::factory(), 'name' => 'Test phone',
            'credential_hash' => fn (array $attributes): string => app(MobileAccess::class)->credential(User::findOrFail($attributes['user_id']))];
    }
}
