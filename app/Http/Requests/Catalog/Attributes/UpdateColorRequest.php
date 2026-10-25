<?php

namespace App\Http\Requests\Catalog\Attributes;

use Illuminate\Validation\Rule;

class UpdateColorRequest extends UpdateColorAppearanceRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('colors', 'name')->ignore($this->route('color'))],
            'hex_code' => ['sometimes', 'nullable', 'string', 'regex:/\A#[0-9A-F]{6}\z/'],
            'code' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'name.required' => 'أدخل اسم اللون.',
            'name.string' => 'أدخل اسم لون صحيحًا.',
            'name.max' => 'اسم اللون يجب ألا يزيد عن 100 حرف.',
            'name.unique' => 'هذا الاسم مستخدم للون آخر.',
            'code.prohibited' => 'رمز اللون ثابت للحفاظ على أكواد الأصناف.',
        ];
    }
}
