<?php

namespace App\Http\Requests\Catalog\Attributes;

class StoreSizeRequest extends \App\Http\Requests\Catalog\StoreSizeRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['code'][0] = 'nullable';

        return $rules;
    }
}
