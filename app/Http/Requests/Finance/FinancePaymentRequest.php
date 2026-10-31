<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class FinancePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('finance.expenses.pay') ?? false;
    }

    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'], 'account_id' => ['required', 'integer', 'min:1'], 'amount' => ['required', 'regex:/\\A\\d{1,12}(?:\\.\\d{1,2})?\\z/'],
            'transaction_date' => ['sometimes', 'date_format:Y-m-d'], 'reference' => ['nullable', 'string', 'max:150'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'confirmed' => ['sometimes', 'boolean'],
        ];
    }
}
