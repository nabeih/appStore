<?php

namespace App\Http\Requests\Branches;

use App\Support\Sales\OperationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = $this->route('branch') ? 'branches.update' : 'branches.create';
        if (! ($this->user()?->can($ability) ?? false)) {
            return false;
        }
        if ($this->route('branch')) {
            OperationContext::branch($this->user(), $this->route('branch')->id, $ability, false);
        }

        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100', Rule::unique('branches')->ignore($this->route('branch')?->id)],
            'address' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'],
            'type' => ['prohibited'], 'is_active' => ['prohibited'], 'branch_id' => ['prohibited'],
            'opening_stock_closed_at' => ['prohibited'], 'opening_stock_closed_by' => ['prohibited'],
            'financial_closed_through' => ['prohibited'], 'first_branch_completed_at' => ['prohibited'], 'initial_branch_id' => ['prohibited']];
    }

    public function attributes(): array
    {
        return ['name' => 'اسم الفرع', 'address' => 'العنوان', 'phone' => 'الهاتف'];
    }

    public function messages(): array
    {
        return ['name.required' => 'أدخل اسم الفرع.', 'name.unique' => 'اسم الفرع مستخدم بالفعل.', '*.prohibited' => 'هذا الحقل لا يمكن تغييره من إدارة بيانات الفرع.'];
    }
}
