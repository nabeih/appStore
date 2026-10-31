<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LegacyTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('finance.transition_review') ?? false;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'min:1'],
            'source_kind' => ['required', Rule::in(['opening', 'purchase', 'payment'])],
            'source_id' => ['required', 'integer', 'min:1'],
            'posting_date' => ['required', 'date_format:Y-m-d'],
            'evidence' => ['required', 'string', 'min:5', 'max:2000'],
            'account_id' => ['nullable', 'integer', 'min:1'],
            'funding_confirmed' => ['sometimes', 'boolean'],
            'opening_inclusion' => ['nullable', Rule::in(['excluded', 'included', 'unknown'])],
            'funding_evidence' => ['nullable', 'string', 'max:2000'],
            'confirmed' => ['sometimes', 'accepted'],
            'reason' => ['nullable', 'string', 'min:3', 'max:1000'],
            'revision' => ['nullable', 'string', 'size:64'],
        ];
    }
}
