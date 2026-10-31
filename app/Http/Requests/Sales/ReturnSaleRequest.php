<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReturnSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.return') ?? false;
    }

    public static function rowRules(string $key = 'rows'): array
    {
        return [$key => ['required', 'array', 'list', 'min:1', 'max:300'], "$key.*" => ['required', 'array:sale_item_id,quantity,condition'],
            "$key.*.sale_item_id" => ['required', 'integer', 'min:1', 'distinct'], "$key.*.quantity" => ['required', 'integer', 'min:1', 'max:1000000'],
            "$key.*.condition" => ['required', Rule::in(['resellable', 'inspection', 'damaged'])]];
    }

    public function rules(): array
    {
        return ['client_uuid' => ['required', 'uuid'], 'customer_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'], 'transaction_date' => ['sometimes', 'date_format:Y-m-d']]
            + self::rowRules() + SaveSaleRequest::paymentRules('refund_payments');
    }

    public function messages(): array
    {
        return ['reason.required' => 'دوّن سبب المرتجع.', 'rows.required' => 'اختر القطع المرتجعة وحدد كميتها وحالتها.'];
    }
}
