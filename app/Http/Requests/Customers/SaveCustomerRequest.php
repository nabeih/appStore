<?php

namespace App\Http\Requests\Customers;

use Illuminate\Foundation\Http\FormRequest;

class SaveCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.customers') ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'phone', 'address', 'notes'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:150'], 'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:1000']];
    }
}
