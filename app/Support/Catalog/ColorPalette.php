<?php

namespace App\Support\Catalog;

class ColorPalette
{
    public static function presets(): array
    {
        return [
            ['name' => 'أسود', 'hex' => '#000000', 'aliases' => ['اسود', 'black']],
            ['name' => 'أبيض', 'hex' => '#FFFFFF', 'aliases' => ['ابيض', 'white']],
            ['name' => 'أحمر', 'hex' => '#DC2626', 'aliases' => ['احمر', 'red']],
            ['name' => 'أزرق', 'hex' => '#2563EB', 'aliases' => ['ازرق', 'blue']],
            ['name' => 'أخضر', 'hex' => '#16A34A', 'aliases' => ['اخضر', 'green']],
            ['name' => 'أصفر', 'hex' => '#FACC15', 'aliases' => ['اصفر', 'yellow']],
            ['name' => 'برتقالي', 'hex' => '#F97316', 'aliases' => ['برتقالي', 'orange']],
            ['name' => 'وردي', 'hex' => '#F472B6', 'aliases' => ['زهري', 'pink', 'rose']],
            ['name' => 'بنفسجي', 'hex' => '#9333EA', 'aliases' => ['موف', 'purple', 'violet']],
            ['name' => 'رمادي', 'hex' => '#6B7280', 'aliases' => ['رصاصي', 'gray', 'grey']],
            ['name' => 'بني', 'hex' => '#92400E', 'aliases' => ['بني', 'brown']],
            ['name' => 'بيج', 'hex' => '#D8C3A5', 'aliases' => ['بيج', 'beige']],
            ['name' => 'كحلي', 'hex' => '#1E3A5F', 'aliases' => ['ازرق غامق', 'أزرق داكن', 'navy', 'navy blue']],
            ['name' => 'سماوي', 'hex' => '#38BDF8', 'aliases' => ['ازرق فاتح', 'أزرق سماوي', 'sky blue', 'light blue']],
            ['name' => 'زيتي', 'hex' => '#6B7C32', 'aliases' => ['زيتوني', 'اخضر زيتي', 'olive']],
            ['name' => 'خمري', 'hex' => '#800020', 'aliases' => ['عنابي', 'نبيتي', 'احمر غامق', 'burgundy', 'maroon']],
            ['name' => 'تركواز', 'hex' => '#14B8A6', 'aliases' => ['فيروزي', 'turquoise', 'teal']],
            ['name' => 'ذهبي', 'hex' => '#D4AF37', 'aliases' => ['دهبي', 'gold', 'golden']],
            ['name' => 'فضي', 'hex' => '#C0C0C0', 'aliases' => ['silver']],
            ['name' => 'سكري', 'hex' => '#FFF8E7', 'aliases' => ['اوف وايت', 'أوف وايت', 'off white', 'cream', 'عاجي', 'ivory']],
            ['name' => 'ليلكي', 'hex' => '#C4B5FD', 'aliases' => ['لافندر', 'lilac', 'lavender']],
            ['name' => 'فوشي', 'hex' => '#D946EF', 'aliases' => ['فوشيا', 'fuchsia', 'magenta']],
        ];
    }

    public static function normalize(string $name): string
    {
        $name = strtr(mb_strtolower(trim($name)), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي']);

        return preg_replace('/[\s\-_\x{0640}\x{064B}-\x{065F}]+/u', '', $name);
    }

    public static function suggest(string $name): ?string
    {
        $name = self::normalize($name);
        foreach (self::presets() as $preset) {
            foreach ([$preset['name'], ...$preset['aliases']] as $alias) {
                if ($name === self::normalize($alias)) {
                    return $preset['hex'];
                }
            }
        }

        return null;
    }
}
