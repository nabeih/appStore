<?php

namespace App\Http\Requests\Catalog\Attributes;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreSizeRequest extends \App\Http\Requests\Catalog\StoreSizeRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        if (is_string($this->input('batch_names')) && filled(trim($this->input('batch_names')))) {
            $this->merge(['names' => array_map('trim', preg_split('/[\r\n,،]+/u', trim($this->input('batch_names'))))]);
        }
        if (is_array($this->input('names'))) {
            if (count($this->input('names')) > 50) {
                throw ValidationException::withMessages(['names' => 'الحد الأقصى 50 مقاسًا في الدفعة.']);
            }
            $this->merge(['names' => array_map(fn ($name) => is_string($name) ? trim($name) : $name, $this->input('names'))]);
        }
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['code'][0] = 'nullable';
        $rules['name'] = ['required_without:names', 'nullable', Rule::prohibitedIf(fn () => filled($this->input('names'))), 'string', 'max:100', Rule::unique('sizes', 'name')];
        $rules['code'][] = Rule::prohibitedIf(fn () => filled($this->input('names')));
        $rules['names'] = ['sometimes', 'array', 'list', 'min:1', 'max:50'];
        $rules['names.*'] = ['required', 'string', 'max:100', 'distinct:ignore_case', Rule::unique('sizes', 'name')];

        return $rules;
    }
}
