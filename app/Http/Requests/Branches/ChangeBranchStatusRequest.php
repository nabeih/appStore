<?php

namespace App\Http\Requests\Branches;

use App\Support\Sales\OperationContext;
use Illuminate\Foundation\Http\FormRequest;

class ChangeBranchStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! ($this->user()?->can('branches.manage_status') ?? false)) {
            return false;
        }
        OperationContext::branch($this->user(), $this->route('branch')->id, 'branches.manage_status', false);

        return true;
    }

    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'name' => ['prohibited'], 'branch_id' => ['prohibited'], 'opening_stock_closed_at' => ['prohibited'], 'financial_closed_through' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['reason.required' => 'اكتب سبب تغيير حالة الفرع.', 'reason.min' => 'اكتب سببًا واضحًا من 3 أحرف على الأقل.'];
    }
}
