<?php

namespace App\Http\Requests\Sales;

use Illuminate\Validation\Rule;

class ExchangeSaleRequest extends SaveSaleRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.exchange') ?? false;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['branch_id'], $rules['invoice_date'], $rules['payment_mode'], $rules['version']);
        $rules['reason'] = ['required', 'string', 'min:3', 'max:500'];
        $rules['settlement_mode'] = ['required', Rule::in(['paid', 'credit'])];

        return $rules + ReturnSaleRequest::rowRules('return_rows') + self::paymentRules('refund_payments');
    }
}
