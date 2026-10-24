<?php

namespace App\Http\Requests\Catalog\Attributes;

use Illuminate\Foundation\Http\FormRequest;

class UpdateColorAppearanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('hex_code'))) {
            $this->merge(['hex_code' => strtoupper(trim($this->input('hex_code')))]);
        }
    }

    public function rules(): array
    {
        return ['hex_code' => ['required', 'string', 'regex:/^#[0-9A-F]{6}$/']];
    }

    public function messages(): array
    {
        return ['hex_code.required' => 'اختر درجة اللون.', 'hex_code.regex' => 'أدخل درجة صحيحة مثل #2563EB.'];
    }
}
