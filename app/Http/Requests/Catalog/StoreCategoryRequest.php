<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->where('parent_id', (is_scalar($this->input('parent_id')) ? $this->input('parent_id') : null))],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['name.required' => 'أدخل اسم التصنيف.', 'name.max' => 'اسم التصنيف يجب ألا يزيد عن 100 حرف.',
            'name.unique' => 'يوجد تصنيف بهذا الاسم تحت التصنيف الرئيسي نفسه.', 'parent_id.exists' => 'اختر تصنيفًا رئيسيًا موجودًا وفعالًا.', 'description.max' => 'الوصف يجب ألا يزيد عن 2000 حرف.'];
    }
}
