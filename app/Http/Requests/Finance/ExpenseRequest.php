<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('finance.expenses.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'], 'branch_id' => ['required', 'integer', 'min:1'], 'title' => ['required', 'string', 'max:200'],
            'category' => ['required', 'in:rent,utilities,maintenance,transport,supplies,other'], 'payee' => ['nullable', 'string', 'max:150'],
            'period_start' => ['required', 'date_format:Y-m-d'], 'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'transaction_date' => ['required', 'date_format:Y-m-d'], 'amount' => ['required', 'regex:/\\A\\d{1,12}(?:\\.\\d{1,2})?\\z/'],
            'reference' => ['nullable', 'string', 'max:150'], 'notes' => ['nullable', 'string', 'max:2000'],
            'external_source' => ['prohibited'], 'external_reference' => ['prohibited'],
        ];
    }
}
