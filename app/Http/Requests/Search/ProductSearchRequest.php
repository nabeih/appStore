<?php

namespace App\Http\Requests\Search;

use App\Actions\Search\SearchTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('catalog.search') ?? false) && $this->user()->can('inventory.view');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => SearchTerm::normalize($this->input('q'))]);
        }
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'branch_id' => ['nullable', 'integer', 'min:1'],
            'color_id' => ['nullable', 'integer', 'min:1'], 'size_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'match' => ['nullable', Rule::in(['contains', 'exact', 'prefix'])],
            'quantity' => ['nullable', Rule::in(['available', 'empty', 'all'])]];
    }
}
