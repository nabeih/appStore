<?php

namespace App\Http\Requests\Purchases;

use App\Models\SupplierPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('purchases.manage') ?? false) && $this->user()->can('sales.view_cost');
    }

    public function rules(): array
    {
        return ['payment_details' => ['nullable', 'array:account_id,confirmed,reference'],
            'payment_details.account_id' => ['required_with:payment_details', 'integer', 'min:1'],
            'payment_details.confirmed' => ['sometimes', 'boolean'], 'payment_details.reference' => ['nullable', 'string', 'max:100'],
            'client_uuid' => ['required', 'uuid'], 'version' => ['nullable', 'integer', 'min:1'],
            'supplier_id' => ['required', 'integer', 'min:1'], 'branch_id' => ['required', 'integer', 'min:1'],
            'invoice_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:2000'],
            'initial_payment' => ['required', 'numeric', 'min:0', 'regex:/\A\d{1,16}(\.\d{1,2})?\z/'],
            'payment_method' => ['nullable', Rule::in(array_keys(SupplierPayment::METHODS))],
            'rows' => ['required', 'array', 'list', 'min:1', 'max:300'],
            'rows.*' => ['required', 'array:product_variant_id,quantity,unit_cost'],
            'rows.*.product_variant_id' => ['required', 'integer', 'min:1', 'distinct:strict'],
            'rows.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'rows.*.unit_cost' => ['required', 'numeric', 'min:0', 'regex:/\A\d{1,7}(\.\d{1,2})?\z/'],
        ];
    }

    public function messages(): array
    {
        return ['payment_details' => ['nullable', 'array:account_id,confirmed,reference'],
            'payment_details.account_id' => ['required_with:payment_details', 'integer', 'min:1'],
            'payment_details.confirmed' => ['sometimes', 'boolean'], 'payment_details.reference' => ['nullable', 'string', 'max:100'], 'required' => 'حقل :attribute مطلوب.', 'integer' => 'حقل :attribute يجب أن يكون عددًا صحيحًا.', 'numeric' => 'حقل :attribute يجب أن يكون رقمًا.',
            'regex' => 'حقل :attribute يجب أن يكون مبلغًا موجبًا أو صفرًا بمنزلتين عشريتين كحد أقصى.',
            'rows.max' => 'الحد الأقصى 300 صنف للفاتورة.', 'rows.*.product_variant_id.distinct' => 'الصنف مكرر، عدّل كميته في السطر الموجود.',
            'rows.*.quantity.max' => 'الحد الأقصى للكمية في السطر مليون قطعة.', 'invoice_date.before_or_equal' => 'لا يمكن اختيار تاريخ مستقبلي.',
            'payment_method.in' => 'اختر طريقة دفع صحيحة.'];
    }

    public function attributes(): array
    {
        return ['payment_details' => ['nullable', 'array:account_id,confirmed,reference'],
            'payment_details.account_id' => ['required_with:payment_details', 'integer', 'min:1'],
            'payment_details.confirmed' => ['sometimes', 'boolean'], 'payment_details.reference' => ['nullable', 'string', 'max:100'], 'supplier_id' => 'المورد', 'branch_id' => 'الفرع', 'invoice_date' => 'تاريخ الفاتورة', 'initial_payment' => 'الدفعة عند الاعتماد',
            'rows' => 'الأصناف', 'rows.*.product_variant_id' => 'الصنف', 'rows.*.quantity' => 'الكمية', 'rows.*.unit_cost' => 'تكلفة الوحدة'];
    }
}
