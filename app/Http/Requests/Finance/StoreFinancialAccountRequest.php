<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.manage_accounts') ?? false;
    }

    public function rules(): array
    {
        return ['branch_id' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(['cash', 'bank', 'wallet'])], 'reference' => ['nullable', 'string', 'max:150'],
            'opening_balance' => ['nullable', 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/'], 'confirm_opening' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500']];
    }
}
