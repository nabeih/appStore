<?php

namespace App\Http\Requests\Onboarding;

use App\Support\Access\AccessPermissions;
use Illuminate\Foundation\Http\FormRequest;

class FirstBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && AccessPermissions::isOwner($this->user());
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100', 'unique:branches,name'],
            'address' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['prohibited'], 'type' => ['prohibited'], 'opening_stock_closed_at' => ['prohibited']];
    }

    public function attributes(): array
    {
        return ['name' => 'اسم الفرع', 'address' => 'العنوان', 'phone' => 'الهاتف'];
    }
}
