<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class TreasuryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('finance.treasury.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'], 'branch_id' => ['required', 'integer', 'min:1'], 'kind' => ['required', 'in:transfer,owner_contribution,owner_withdrawal'],
            'from_account_id' => ['nullable', 'integer', 'min:1'], 'to_account_id' => ['nullable', 'integer', 'min:1'],
            'transaction_date' => ['sometimes', 'date_format:Y-m-d'], 'amount' => ['required', 'regex:/\\A\\d{1,12}(?:\\.\\d{1,2})?\\z/'],
            'reference' => ['nullable', 'string', 'max:150'], 'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
