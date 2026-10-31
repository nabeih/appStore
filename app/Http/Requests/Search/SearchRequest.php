<?php

namespace App\Http\Requests\Search;

use App\Actions\Search\SearchTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => SearchTerm::normalize($this->input('q'))]);
        }
    }

    public function rules(): array
    {
        return self::filterRules() + ['type' => ['nullable', Rule::in(['products', 'customers', 'suppliers', 'sales', 'purchases', 'openings', 'returns', 'exchanges', 'receipts', 'supplier_payments', 'accounts', 'categories', 'colors', 'sizes'])]];
    }

    public static function filterRules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'account_id' => ['nullable', 'integer', 'min:1'], 'branch_id' => ['nullable', 'integer', 'min:1'], 'category_id' => ['nullable', 'integer', 'min:1'],
            'color_id' => ['nullable', 'integer', 'min:1'], 'size_id' => ['nullable', 'integer', 'min:1'],
            'active' => ['nullable', Rule::in(['all', 'active', 'inactive'])], 'quantity' => ['nullable', Rule::in(['all', 'available', 'empty'])],
            'match' => ['nullable', Rule::in(['contains', 'prefix', 'exact'])], 'sort' => ['nullable', Rule::in(['newest', 'oldest', 'name'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(['draft', 'approved', 'cancelled', 'reversed'])],
            'kind' => ['nullable', Rule::in(['receipt', 'refund', 'credit'])], 'account_type' => ['nullable', Rule::in(['cash', 'bank', 'wallet'])],
            'min_amount' => ['nullable', 'regex:/\\A\\d{1,12}(?:\\.\\d{1,2})?\\z/'],
            'max_amount' => ['nullable', 'regex:/\\A\\d{1,12}(?:\\.\\d{1,2})?\\z/'],
            'valuation' => ['nullable', Rule::in(['missing', 'reviewed', 'mismatch'])]];
    }
}
