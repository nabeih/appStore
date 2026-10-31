<?php

namespace App\Http\Requests\Catalog\Attributes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
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
        $category = $this->route('category');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->where('parent_id', $category->parent_id)->ignore($category)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'parent_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['name.required' => 'أدخل اسم التصنيف.', 'name.unique' => 'يوجد تصنيف بهذا الاسم تحت التصنيف الرئيسي نفسه.',
            'name.max' => 'اسم التصنيف يجب ألا يزيد عن 100 حرف.', 'description.max' => 'الوصف يجب ألا يزيد عن 2000 حرف.',
            'parent_id.prohibited' => 'هذا الإجراء لتعديل اسم التصنيف ووصفه.'];
    }
}
