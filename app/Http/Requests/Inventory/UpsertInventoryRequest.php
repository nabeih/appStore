<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class UpsertInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // مؤقتًا للتطوير المحلي
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:0'],
            'average_cost' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ];
    }
}
