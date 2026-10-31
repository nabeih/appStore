<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class ReverseOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.reverse') ?? false;
    }

    public function rules(): array
    {
        return ['client_uuid' => ['required', 'uuid'], 'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'goods_confirmed' => ['required', 'boolean'], 'funds_confirmed' => ['required', 'accepted']]
            + SaveSaleRequest::paymentRules('refund_payments');
    }
}
