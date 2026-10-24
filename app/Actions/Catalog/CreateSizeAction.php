<?php

namespace App\Actions\Catalog;

use App\Models\Size;
use Illuminate\Support\Str;

class CreateSizeAction
{
    public function execute(array $data): Size
    {
        $code = $data['code'] ?? null;
        if (blank($code)) {
            $candidate = strtoupper(trim($data['name']));
            $code = preg_match('/^[A-Z0-9_-]{1,20}$/', $candidate) && ! Size::where('code', $candidate)->exists()
                ? $candidate : 'S-'.strtoupper(Str::random(10));
        }

        return Size::create(['name' => trim($data['name']), 'code' => strtoupper($code), 'sort_order' => $data['sort_order'] ?? 0]);
    }
}
