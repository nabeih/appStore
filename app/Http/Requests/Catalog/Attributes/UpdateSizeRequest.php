<?php

namespace App\Http\Requests\Catalog\Attributes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.manage_catalog') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('sizes', 'name')->ignore($this->route('size'))],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'code' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['name.required' => 'أدخل اسم المقاس.', 'name.unique' => 'هذا الاسم مستخدم لمقاس آخر.',
            'name.max' => 'اسم المقاس يجب ألا يزيد عن 100 حرف.', 'code.prohibited' => 'رمز المقاس ثابت للحفاظ على أكواد الأصناف.',
            'sort_order.integer' => 'الترتيب يجب أن يكون عددًا صحيحًا.', 'sort_order.min' => 'الترتيب لا يمكن أن يكون سالبًا.',
            'sort_order.max' => 'الترتيب يتجاوز الحد المسموح.'];
    }
}
