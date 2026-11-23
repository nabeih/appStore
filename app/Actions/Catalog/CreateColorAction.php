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
            'code' => filled($data['code'] ?? null) ? strtoupper(trim($data['code'])) : $this->suggestCode($data['name'], $data['hex_code'] ?? null),
            'hex_code' => filled($data['hex_code'] ?? null) ? strtoupper($data['hex_code']) : ColorPalette::suggest($data['name']),
        ]);
    }

    private function suggestCode(string $name, ?string $hex = null): string
    {
        $common = ['black' => 'BLK', 'white' => 'WHT', 'red' => 'RED', 'blue' => 'BLU', 'green' => 'GRN',
            'yellow' => 'YLW', 'orange' => 'ORG', 'pink' => 'PNK', 'purple' => 'PUR', 'gray' => 'GRY', 'brown' => 'BRN', 'beige' => 'BGE'];
        foreach (ColorPalette::presets() as $preset) {
            if (in_array(ColorPalette::normalize($name), array_map([ColorPalette::class, 'normalize'], [$preset['name'], ...$preset['aliases']]), true)) {
                foreach ($preset['aliases'] as $alias) {
                    if (preg_match('/\A[a-z ]+\z/i', $alias)) {
                        $base = $common[$alias] ?? strtoupper(substr(str_replace(' ', '-', $alias), 0, 12));

                        return Color::where('code', $base)->exists() ? $base.'-'.strtoupper(Str::random(6)) : $base;
                    }
                }
            }
        }

        $slug = strtoupper(substr(Str::slug($name), 0, 12));
        $base = $hex && preg_match('/\A#[0-9A-Fa-f]{6}\z/', $hex) ? 'HEX-'.strtoupper(substr($hex, 1))
            : ($slug !== '' ? $slug : 'CLR-'.strtoupper(substr(hash('sha256', ColorPalette::normalize($name)), 0, 6)));

        return Color::where('code', $base)->exists() ? $base.'-'.strtoupper(Str::random(6)) : $base;
    }
}
