<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.sell') ?? false;
    }

    public static function paymentRules(string $key = 'payments'): array
    {
        return [$key => ['sometimes', 'array', 'list', 'max:10'], "$key.*" => ['required', 'array:account_id,amount,confirmed,reference'],
            "$key.*.account_id" => ['required', 'integer', 'min:1', 'distinct'], "$key.*.amount" => ['nullable', 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/'],
            "$key.*.confirmed" => ['sometimes', 'boolean'], "$key.*.reference" => ['nullable', 'string', 'max:150']];
    }

    public static function discountRules(string $key = 'discount'): array
    {
        return [$key => ['sometimes', 'array:type,value'], "$key.type" => ['required_with:'.$key, Rule::in(['amount', 'percent'])],
            "$key.value" => ['required_with:'.$key, 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/']];
    }

    public function rules(): array
    {
        return ['client_uuid' => ['required', 'uuid'], 'version' => ['sometimes', 'integer', 'min:1'],
            'branch_id' => ['required', 'integer', 'min:1'], 'customer_id' => ['nullable', 'integer', 'min:1'],
            'invoice_date' => ['sometimes', 'date_format:Y-m-d'], 'payment_mode' => ['required', Rule::in(['paid', 'partial', 'credit'])],
            'reason' => ['nullable', 'string', 'max:500'], 'notes' => ['nullable', 'string', 'max:2000'],
            'rows' => ['required', 'array', 'list', 'min:1', 'max:300'],
            'rows.*' => ['required', 'array:product_variant_id,quantity,unit_price,discount,price_reason'],
            'rows.*.product_variant_id' => ['required', 'integer', 'min:1', 'distinct'],
            'rows.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'rows.*.unit_price' => ['nullable', 'regex:/\A\d{1,7}(?:\.\d{1,2})?\z/'], 'rows.*.price_reason' => ['nullable', 'string', 'max:500']]
            + self::paymentRules() + self::discountRules() + self::discountRules('rows.*.discount');
    }

    public function messages(): array
    {
        return ['required' => 'الحقل :attribute مطلوب.', 'integer' => 'أدخل عددًا صحيحًا في :attribute.',
            'regex' => 'أدخل قيمة صحيحة بمنزلتين عشريتين كحد أقصى.', 'distinct' => 'العنصر مكرر؛ عدّل كميته أو مبلغه بدل تكراره.'];
    }
}
