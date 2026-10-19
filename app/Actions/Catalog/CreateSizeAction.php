<?php

namespace App\Actions\Catalog;

use App\Models\Size;

class CreateSizeAction
{
    public function execute(array $data): Size
    {
        return Size::create([
            'name' => trim($data['name']),
            'code' => strtoupper($data['code']),
        ]);
    }
}
