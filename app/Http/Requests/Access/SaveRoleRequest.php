<?php

namespace App\Http\Requests\Access;

use App\Support\Sales\SalesAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access.roles.manage') ?? false;
    }

    public function messages(): array
    {
        return ['display_name.required' => 'أدخل اسم الدور.', 'display_name.max' => 'اسم الدور يجب ألا يتجاوز 150 حرفًا.', 'is_active.required' => 'حدد حالة الدور.', 'is_active.boolean' => 'حالة الدور غير صالحة.', 'permissions.*.in' => 'إحدى الصلاحيات غير معروفة.', 'permissions.*.distinct' => 'لا تكرر الصلاحية.', 'permissions.array' => 'اختر الصلاحيات من القائمة.', 'name.prohibited' => 'الاسم التقني للدور محمي.'];
    }

    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:150'],
            'is_active' => ['required', 'boolean'],
            'permissions' => ['sometimes', 'array', 'list', 'max:100'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(array_keys(SalesAccess::PERMISSIONS))],
            'name' => ['prohibited'], 'role_id' => ['prohibited'],
        ];
    }
}
