<?php

namespace App\Http\Requests\Catalog\Attributes;

class StoreColorRequest extends \App\Http\Requests\Catalog\StoreColorRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['code'][0] = 'nullable';
        $rules['hex_code'][0] = 'required';

        return $rules;
    }
}
