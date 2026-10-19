<?php

namespace App\Actions\Catalog;

use App\Models\Color;

class CreateColorAction
{
    public function execute(array $data): Color
    {
        return Color::create([
            'name' => trim($data['name']),
            'code' => strtoupper($data['code']),
        ]);
    }
}
