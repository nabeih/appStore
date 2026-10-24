<?php

namespace App\Http\Requests\Inventory\Setup;

class UpdateInventoryRequest extends DeleteInventoryRequest
{
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
        $money = ['required', 'numeric', 'min:0', 'max:9999999.99', 'regex:/^\d{1,7}(\.\d{1,2})?$/'];

        return array_merge(parent::rules(), [
            'name' => ['required', 'string', 'max:200'],
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/'],
            'category_id' => ['required', 'integer', 'min:1'],
            'color_id' => ['required', 'integer', 'min:1'],
            'size_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'average_cost' => $money,
            'selling_price' => $money,
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.required' => 'أدخل اسم المنتج.',
            'name.max' => 'اسم المنتج يجب ألا يزيد عن 200 حرف.',
            'code.required' => 'أدخل كود المنتج.',
            'code.regex' => 'كود المنتج يقبل أحرفًا إنجليزية وأرقامًا وشرطة أو شرطة سفلية.',
            'code.max' => 'الكود يجب ألا يزيد عن 50 حرفًا.',
            'category_id.required' => 'اختر التصنيف.',
            'color_id.required' => 'اختر اللون.',
            'size_id.required' => 'اختر المقاس.',
            'quantity.required' => 'أدخل الكمية.',
            'quantity.integer' => 'الكمية يجب أن تكون عددًا صحيحًا.',
            'quantity.min' => 'الكمية لا يمكن أن تكون سالبة.',
            'quantity.max' => 'الكمية تتجاوز الحد المسموح.',
            'average_cost.required' => 'أدخل تكلفة الوحدة.',
            'average_cost.numeric' => 'أدخل تكلفة صحيحة.',
            'average_cost.min' => 'التكلفة لا يمكن أن تكون سالبة.',
            'average_cost.max' => 'التكلفة تتجاوز الحد المسموح.',
            'average_cost.regex' => 'التكلفة تقبل منزلتين عشريتين كحد أقصى.',
            'selling_price.required' => 'أدخل سعر البيع.',
            'selling_price.numeric' => 'أدخل سعر بيع صحيحًا.',
            'selling_price.min' => 'سعر البيع لا يمكن أن يكون سالبًا.',
            'selling_price.max' => 'سعر البيع يتجاوز الحد المسموح.',
            'selling_price.regex' => 'سعر البيع يقبل منزلتين عشريتين كحد أقصى.',
        ]);
    }
}
