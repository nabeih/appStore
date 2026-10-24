<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Inventory\Setup\UpdateInventoryRequest;

class UpsertInventoryRequest extends UpdateInventoryRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['selling_price', 'name', 'code', 'category_id', 'color_id', 'size_id'] as $field) {
            $rules[$field][0] = 'sometimes';
        }

        return $rules;
    }
}
