<?php

namespace App\Http\Requests\Suppliers;

use Illuminate\Foundation\Http\FormRequest;

class SaveSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'phone1' => ['nullable', 'string', 'max:30'],
            'phone2' => ['nullable', 'string', 'max:30'], 'address' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000']];
    }

    public function messages(): array
    {
        return ['name.required' => 'أدخل اسم المورد.', 'name.max' => 'اسم المورد طويل جدًا.', '*.string' => 'أدخل قيمة نصية صحيحة.', '*.max' => 'القيمة أطول من الحد المسموح.'];
    }
}
