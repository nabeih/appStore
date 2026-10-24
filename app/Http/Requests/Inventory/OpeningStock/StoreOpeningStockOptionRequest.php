<?php

namespace App\Http\Requests\Inventory\OpeningStock;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpeningStockOptionRequest extends FormRequest
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
        $table = match ($this->input('type')) {
            'color' => 'colors', 'size' => 'sizes', default => 'categories'
        };

        return [
            'hex_code' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'type' => ['required', Rule::in(['category', 'color', 'size'])],
            'name' => ['required', 'string', 'max:100', Rule::unique($table, 'name')],
            'code' => [Rule::requiredIf(in_array($this->input('type'), ['color', 'size'], true)), 'nullable', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/', Rule::unique($table === 'categories' ? 'colors' : $table, 'code')],
        ];
    }

    public function messages(): array
    {
        return ['name.required' => 'أدخل الاسم.', 'name.unique' => 'هذا الاسم موجود مسبقًا.', 'name.max' => 'الاسم يجب ألا يزيد عن 100 حرف.', 'code.required' => 'أدخل كودًا للون أو المقاس.', 'code.unique' => 'هذا الكود مستخدم مسبقًا.', 'code.regex' => 'الكود يقبل أحرفًا إنجليزية وأرقامًا وشرطة أو شرطة سفلية.', 'code.max' => 'الكود يجب ألا يزيد عن 20 حرفًا.'];
    }
}
