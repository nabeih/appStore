<?php

namespace App\Http\Requests\Catalog;

use App\Support\Catalog\ColorPalette;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreColorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'code', 'hex_code'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => $field === 'name' ? trim($this->input($field)) : strtoupper(trim($this->input($field)))]);
            }
        }
        if (blank($this->input('hex_code')) && is_string($this->input('name'))) {
            $this->merge(['hex_code' => ColorPalette::suggest($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('colors', 'name')],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('colors', 'code')],
            'hex_code' => ['nullable', 'string', 'regex:/^#[0-9A-F]{6}$/'],
        ];
    }

    public function messages(): array
    {
        return ['name.required' => 'أدخل اسم اللون.', 'name.unique' => 'هذا اللون موجود مسبقًا.', 'name.max' => 'اسم اللون يجب ألا يزيد عن 100 حرف.',
            'code.required' => 'أدخل رمز اللون.', 'code.unique' => 'الرمز مستخدم للون آخر.', 'code.max' => 'الرمز يجب ألا يزيد عن 20 حرفًا.',
            'code.regex' => 'الرمز يقبل أحرفًا إنجليزية وأرقامًا وشرطة أو شرطة سفلية.',
            'hex_code.required' => 'اختر درجة اللون من المنتقي أو أدخل رمزها.', 'hex_code.regex' => 'درجة اللون يجب أن تكون بصيغة مثل #2563EB.'];
    }
}
