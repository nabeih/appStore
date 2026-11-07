<?php

namespace App\Http\Requests\Finance;

use App\Actions\Finance\ChartOfAccounts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChartAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('finance.view') && $this->user()->can('sales.view_cost') && $this->user()->can('sales.manage_accounts');
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:80', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'name' => ['required', 'string', 'max:150'], 'parent_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'], 'account_group' => ['required', Rule::in(array_keys(ChartOfAccounts::GROUPS))],
            'normal_balance' => ['required', 'in:debit,credit'], 'is_posting' => ['required', 'boolean'], 'is_active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'], 'type' => ['prohibited'], 'is_protected' => ['prohibited']];
    }
}
