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
        $payment = $this->input('source_kind') === 'payment';
        $posting = $this->routeIs('finance.transition.post');

        return [
            'branch_id' => ['required', 'integer', 'min:1'],
            'source_kind' => ['required', Rule::in(['opening', 'purchase', 'payment'])],
            'source_id' => ['required', 'integer', 'min:1'],
            'posting_date' => ['required', 'date_format:Y-m-d'],
            'evidence' => ['required', 'string', 'min:5', 'max:2000'],
            'account_id' => [Rule::excludeIf(! $payment), $posting ? 'required' : 'nullable', 'integer', 'min:1'],
            'funding_confirmed' => [Rule::excludeIf(! $payment), ...($posting ? ['required', 'accepted'] : ['sometimes', 'boolean'])],
            'opening_inclusion' => [Rule::excludeIf(! $payment), $posting ? 'required' : 'nullable', Rule::in($posting ? ['excluded'] : ['excluded', 'included', 'unknown'])],
            'funding_evidence' => [Rule::excludeIf(! $payment), $posting ? 'required' : 'nullable', 'string', 'min:5', 'max:2000'],
            'confirmed' => $posting ? ['required', 'accepted'] : ['sometimes', 'boolean'],
            'reason' => [$posting ? 'required' : 'nullable', 'string', 'min:3', 'max:1000'],
            'revision' => ['nullable', 'string', 'size:64'],
        ];
    }
}
