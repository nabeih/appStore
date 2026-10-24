<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSizeRequest extends FormRequest
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
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('sizes', 'name')],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('sizes', 'code')],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function messages(): array
    {
        return ['name.required' => 'أدخل اسم المقاس.', 'name.unique' => 'هذا المقاس موجود مسبقًا.', 'name.max' => 'اسم المقاس يجب ألا يزيد عن 100 حرف.',
            'code.required' => 'أدخل رمز المقاس.', 'code.unique' => 'الرمز مستخدم لمقاس آخر.', 'code.max' => 'الرمز يجب ألا يزيد عن 20 حرفًا.',
            'code.regex' => 'الرمز يقبل أحرفًا إنجليزية وأرقامًا وشرطة أو شرطة سفلية.', 'sort_order.integer' => 'ترتيب العرض يجب أن يكون عددًا صحيحًا.', 'sort_order.min' => 'ترتيب العرض لا يمكن أن يكون سالبًا.', 'sort_order.max' => 'ترتيب العرض يتجاوز الحد المسموح.'];
    }
}
