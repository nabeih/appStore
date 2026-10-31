<?php

namespace App\Http\Requests\Access;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access.users.manage') ?? false;
    }

    public function messages(): array
    {
        return ['role_id.required' => 'اختر دور الموظف.', 'role_id.exists' => 'الدور غير موجود أو معطل.', 'branch_id.exists' => 'الفرع غير موجود أو معطل.', 'is_active.required' => 'حدد حالة وصول الموظف.', 'is_active.boolean' => 'حالة الحساب غير صالحة.', 'permissions.prohibited' => 'تُحدد الصلاحيات من الدور فقط.', 'password.prohibited' => 'هذه الشاشة لا تغير كلمات المرور.', 'username.prohibited' => 'هذه الشاشة لا تغير أسماء المستخدمين.'];
    }

    public function rules(): array
    {
        return ['role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('is_active', true)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'is_active' => ['required', 'boolean'],
            'password' => ['prohibited'], 'permissions' => ['prohibited'], 'username' => ['prohibited']];
    }
}
