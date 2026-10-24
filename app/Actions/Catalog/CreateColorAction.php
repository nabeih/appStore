<?php

namespace App\Actions\Catalog;

use App\Models\Color;
use App\Support\Catalog\ColorPalette;
use Illuminate\Support\Str;

class CreateColorAction
{
    public function execute(array $data): Color
    {
        return Color::create([
            'name' => trim($data['name']),
            'code' => filled($data['code'] ?? null) ? strtoupper(trim($data['code'])) : 'C-'.strtoupper(Str::random(10)),
            'hex_code' => filled($data['hex_code'] ?? null) ? strtoupper($data['hex_code']) : ColorPalette::suggest($data['name']),
        ]);
    }
}
