<?php

namespace App\Http\Requests\Customers;

use App\Http\Requests\Sales\SaveSaleRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.collect') ?? false;
    }

    public function rules(): array
    {
        return ['client_uuid' => ['required', 'uuid'], 'branch_id' => ['required', 'integer', 'min:1'], 'customer_id' => ['required', 'integer', 'min:1'],
            'kind' => ['required', Rule::in(['receipt', 'credit', 'refund', 'advance'])], 'transaction_date' => ['sometimes', 'date_format:Y-m-d'],
            'amount' => ['required_if:kind,advance', 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/'], 'refund_confirmed' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'], 'allocations' => ['sometimes', 'array', 'list', 'max:300'],
            'allocations.*' => ['required', 'array:sale_invoice_id,amount'], 'allocations.*.sale_invoice_id' => ['required', 'integer', 'min:1', 'distinct'],
            'allocations.*.amount' => ['required', 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/'],
            'credits' => ['sometimes', 'array', 'list', 'max:100'], 'credits.*' => ['required', 'array:sale_return_id,advance_receipt_id,amount'],
            'credits.*.sale_return_id' => ['required_without:credits.*.advance_receipt_id', 'prohibits:credits.*.advance_receipt_id', 'integer', 'min:1', 'distinct'],
            'credits.*.advance_receipt_id' => ['required_without:credits.*.sale_return_id', 'integer', 'min:1', 'distinct'],
            'credits.*.amount' => ['required', 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/']] + SaveSaleRequest::paymentRules();
    }
}
