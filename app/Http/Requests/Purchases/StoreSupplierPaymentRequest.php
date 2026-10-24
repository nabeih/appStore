<?php

namespace App\Http\Requests\Purchases;

use App\Models\SupplierPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['client_uuid' => ['required', 'uuid'], 'amount' => ['required', 'numeric', 'gt:0', 'regex:/\A\d{1,16}(\.\d{1,2})?\z/'],
            'method' => ['required', Rule::in(array_keys(SupplierPayment::METHODS))],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:500']];
    }

    public function messages(): array
    {
        return ['amount.gt' => 'مبلغ الدفعة يجب أن يكون أكبر من صفر.', 'amount.regex' => 'أدخل مبلغًا صالحًا بمنزلتين عشريتين كحد أقصى.',
            'payment_date.before_or_equal' => 'لا يمكن تسجيل دفعة بتاريخ مستقبلي.', 'method.in' => 'اختر طريقة دفع صحيحة.', 'required' => 'حقل :attribute مطلوب.'];
    }
}
