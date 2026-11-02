<?php

namespace Database\Factories;

use App\Models\StoreSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreSetting> */
class StoreSettingFactory extends Factory
{
    public function definition(): array
    {
        return ['id' => 1, 'branch_id' => BranchFactory::new(), 'name' => 'متجر اختبار', 'policy' => 'عند رفض الاستلام تستحق رسوم المنطقة الأصلية حتى لو كان التوصيل مجانيًا.', 'is_open' => true];
    }
}
