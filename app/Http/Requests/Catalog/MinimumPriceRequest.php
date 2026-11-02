<?php

namespace App\Http\Requests\Catalog;

use App\Support\Access\AccessPermissions;
use Illuminate\Foundation\Http\FormRequest;

class MinimumPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && AccessPermissions::isOwner($this->user()) && $this->user()->can('inventory.manage_catalog');
    }

    public function rules(): array
    {
        return ['apply_all' => ['sometimes', 'boolean'], 'minimum' => ['nullable', 'regex:/\\A\\d{1,7}(?:\\.\\d{1,2})?\\z/'],
            'variants' => ['required', 'array', 'list', 'min:1', 'max:300'], 'variants.*' => ['required', 'array:id,minimum'],
            'variants.*.id' => ['required', 'integer', 'min:1', 'distinct'], 'variants.*.minimum' => ['nullable', 'regex:/\\A\\d{1,7}(?:\\.\\d{1,2})?\\z/']];
    }
}
