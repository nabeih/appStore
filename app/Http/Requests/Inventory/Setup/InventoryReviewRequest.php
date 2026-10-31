<?php

namespace App\Http\Requests\Inventory\Setup;

use Illuminate\Foundation\Http\FormRequest;

class InventoryReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.valuation_review') ?? false;
    }

    public function rules(): array
    {
        return ['revision' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'min:3', 'max:500'], 'confirmed' => ['required', 'accepted'],
            'approved_value' => ['required', 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/'],
            'basis' => ['required', 'in:documents,owner_evidence'], 'evidence' => ['required', 'string', 'min:5', 'max:1000']];
    }
}
