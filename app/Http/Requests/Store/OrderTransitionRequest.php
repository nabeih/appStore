<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('store.orders.manage') ?? false;
    }

    public function rules(): array
    {
        $base = ['operation_uuid' => ['required', 'uuid'], 'reason' => ['required', 'string', 'min:3', 'max:500']];
        if ($this->routeIs('*.payment')) {
            return $base + ['kind' => ['required', Rule::in(['collect', 'remit', 'provider_pay'])],
                'received_by' => ['required', Rule::in(['store', 'courier'])], 'amount' => ['required', 'regex:/\\A\\d{1,10}(?:\\.\\d{1,2})?\\z/'],
                'method' => ['required', Rule::in(['cash', 'bank', 'wallet'])], 'account_id' => ['nullable', 'integer', 'min:1'],
                'confirmed' => ['sometimes', 'boolean'], 'reference' => ['nullable', 'string', 'max:100']];
        }

        return $base + ['action' => ['required', Rule::in(['confirm', 'dispatch', 'deliver', 'cancel', 'refuse', 'return_received'])],
            'version' => ['required', 'integer', 'min:1'], 'courier_name' => ['nullable', 'string', 'max:150'], 'below_minimum_confirmed' => ['sometimes', 'boolean']];
    }
}
