<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('purchases.manage') ?? false) && $this->user()->can('sales.view_cost');
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['version.required' => 'حدّث الفاتورة قبل تنفيذ العملية.', 'version.integer' => 'نسخة الفاتورة غير صالحة.'];
    }
}
