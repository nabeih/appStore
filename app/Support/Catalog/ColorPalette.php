<?php

namespace App\Support\Catalog;

class ColorPalette
{
    /** @var array<string, string>|null */
    private static ?array $suggestions = null;

    /** @return list<array{name: string, hex: string, aliases: list<string>, family: string}> */
    public static function presets(): array
    {
        return [
            ['name' => 'أسود', 'hex' => '#000000', 'aliases' => ['اسود', 'black'], 'family' => 'أساسية'],
            ['name' => 'أبيض', 'hex' => '#FFFFFF', 'aliases' => ['ابيض', 'white'], 'family' => 'أساسية'],
            ['name' => 'أحمر', 'hex' => '#DC2626', 'aliases' => ['احمر', 'red'], 'family' => 'أساسية'],
            ['name' => 'أزرق', 'hex' => '#2563EB', 'aliases' => ['ازرق', 'blue'], 'family' => 'أساسية'],
            ['name' => 'أخضر', 'hex' => '#16A34A', 'aliases' => ['اخضر', 'green'], 'family' => 'أساسية'],
            ['name' => 'أصفر', 'hex' => '#FACC15', 'aliases' => ['اصفر', 'yellow'], 'family' => 'أساسية'],
            ['name' => 'برتقالي', 'hex' => '#F97316', 'aliases' => ['orange'], 'family' => 'أساسية'],
            ['name' => 'وردي', 'hex' => '#F472B6', 'aliases' => ['زهري', 'pink', 'rose'], 'family' => 'أساسية'],
            ['name' => 'بنفسجي', 'hex' => '#9333EA', 'aliases' => ['موف', 'purple', 'violet'], 'family' => 'أساسية'],
            ['name' => 'رمادي', 'hex' => '#6B7280', 'aliases' => ['رصاصي', 'gray', 'grey'], 'family' => 'أساسية'],
            ['name' => 'بني', 'hex' => '#92400E', 'aliases' => ['brown'], 'family' => 'أساسية'],
            ['name' => 'بيج', 'hex' => '#D8C3A5', 'aliases' => ['beige'], 'family' => 'أساسية'],
            ['name' => 'سكري', 'hex' => '#FFF8E7', 'aliases' => ['اوف وايت', 'أوف وايت', 'off white', 'cream', 'عاجي', 'ivory'], 'family' => 'حيادية'],
            ['name' => 'فضي', 'hex' => '#C0C0C0', 'aliases' => ['silver'], 'family' => 'حيادية'],
            ['name' => 'لؤلؤي', 'hex' => '#F3F0E7', 'aliases' => ['لؤلؤ', 'pearl'], 'family' => 'حيادية'],
            ['name' => 'رمادي فاتح', 'hex' => '#D1D5DB', 'aliases' => ['light gray', 'light grey'], 'family' => 'حيادية'],
            ['name' => 'رمادي داكن', 'hex' => '#374151', 'aliases' => ['رمادي غامق', 'dark gray'], 'family' => 'حيادية'],
            ['name' => 'فحمي', 'hex' => '#2B2D31', 'aliases' => ['charcoal'], 'family' => 'حيادية'],
            ['name' => 'جرافيت', 'hex' => '#42464D', 'aliases' => ['graphite'], 'family' => 'حيادية'],
            ['name' => 'رمادي دافئ', 'hex' => '#A8A29E', 'aliases' => ['warm gray'], 'family' => 'حيادية'],
            ['name' => 'رمادي بارد', 'hex' => '#94A3B8', 'aliases' => ['cool gray'], 'family' => 'حيادية'],
            ['name' => 'جريج', 'hex' => '#B6ADA1', 'aliases' => ['greige'], 'family' => 'حيادية'],
            ['name' => 'تاوب', 'hex' => '#9C8B7A', 'aliases' => ['taupe'], 'family' => 'حيادية'],
            ['name' => 'حجري', 'hex' => '#B8B3A9', 'aliases' => ['stone'], 'family' => 'حيادية'],
            ['name' => 'رملي', 'hex' => '#D6C1A2', 'aliases' => ['sand'], 'family' => 'ترابية'],
            ['name' => 'كتاني', 'hex' => '#E8DCC8', 'aliases' => ['linen'], 'family' => 'ترابية'],
            ['name' => 'جملي', 'hex' => '#C19A6B', 'aliases' => ['camel'], 'family' => 'ترابية'],
            ['name' => 'كراميل', 'hex' => '#B77945', 'aliases' => ['caramel'], 'family' => 'ترابية'],
            ['name' => 'عسلي', 'hex' => '#C99235', 'aliases' => ['honey'], 'family' => 'ترابية'],
            ['name' => 'موكا', 'hex' => '#8B6F5A', 'aliases' => ['mocha'], 'family' => 'ترابية'],
            ['name' => 'شوكولاتة', 'hex' => '#4E342E', 'aliases' => ['شوكولاته', 'chocolate'], 'family' => 'ترابية'],
            ['name' => 'إسبريسو', 'hex' => '#36261F', 'aliases' => ['اسبريسو', 'espresso'], 'family' => 'ترابية'],
            ['name' => 'قهوة', 'hex' => '#6F4E37', 'aliases' => ['coffee'], 'family' => 'ترابية'],
            ['name' => 'كاكاو', 'hex' => '#7B5B4B', 'aliases' => ['cocoa'], 'family' => 'ترابية'],
            ['name' => 'طيني', 'hex' => '#B66A50', 'aliases' => ['تيراكوتا', 'terracotta', 'clay'], 'family' => 'ترابية'],
            ['name' => 'صدئي', 'hex' => '#A64B32', 'aliases' => ['rust'], 'family' => 'ترابية'],
            ['name' => 'خمري', 'hex' => '#800020', 'aliases' => ['عنابي', 'نبيتي', 'احمر غامق', 'burgundy', 'maroon'], 'family' => 'وردي وأحمر'],
            ['name' => 'أحمر كرزي', 'hex' => '#B91C3C', 'aliases' => ['كرزي', 'cherry red'], 'family' => 'وردي وأحمر'],
            ['name' => 'أحمر طوبي', 'hex' => '#A63D40', 'aliases' => ['طوبي', 'brick red'], 'family' => 'وردي وأحمر'],
            ['name' => 'توتي', 'hex' => '#9F2B68', 'aliases' => ['berry'], 'family' => 'وردي وأحمر'],
            ['name' => 'روبي', 'hex' => '#BE123C', 'aliases' => ['ياقوتي', 'ruby'], 'family' => 'وردي وأحمر'],
            ['name' => 'مرجاني', 'hex' => '#FF7F73', 'aliases' => ['coral'], 'family' => 'وردي وأحمر'],
            ['name' => 'سلموني', 'hex' => '#FA9F8F', 'aliases' => ['سيمون', 'salmon'], 'family' => 'وردي وأحمر'],
            ['name' => 'بودري', 'hex' => '#E8C5C8', 'aliases' => ['وردي بودري', 'blush'], 'family' => 'وردي وأحمر'],
            ['name' => 'وردي غباري', 'hex' => '#C99CA4', 'aliases' => ['وردي ترابي', 'dusty rose'], 'family' => 'وردي وأحمر'],
            ['name' => 'وردي باهت', 'hex' => '#F4D9DF', 'aliases' => ['بيبي بينك', 'baby pink'], 'family' => 'وردي وأحمر'],
            ['name' => 'روز وود', 'hex' => '#A56A73', 'aliases' => ['rosewood'], 'family' => 'وردي وأحمر'],
            ['name' => 'أحمر قرميدي', 'hex' => '#9C3B2E', 'aliases' => ['قرميدي', 'burnt red'], 'family' => 'وردي وأحمر'],
            ['name' => 'ذهبي', 'hex' => '#D4AF37', 'aliases' => ['دهبي', 'gold', 'golden'], 'family' => 'برتقالي وأصفر'],
            ['name' => 'ذهبي وردي', 'hex' => '#B76E79', 'aliases' => ['روز جولد', 'rose gold'], 'family' => 'برتقالي وأصفر'],
            ['name' => 'نحاسي', 'hex' => '#B87333', 'aliases' => ['copper'], 'family' => 'برتقالي وأصفر'],
            ['name' => 'برونزي', 'hex' => '#A97142', 'aliases' => ['bronze'], 'family' => 'برتقالي وأصفر'],
            ['name' => 'مشمشي', 'hex' => '#F5B88C', 'aliases' => ['apricot'], 'family' => 'برتقالي وأصفر'],
            ['name' => 'خوخي', 'hex' => '#FFD2B0', 'aliases' => ['peach'], 'family' => 'برتقالي وأصفر'],
            ['name' => 'مانجو', 'hex' => '#FFB347', 'aliases' => ['mango'], 'family' => 'برتقالي وأصفر'],
            ['name' => 'زعفراني', 'hex' => '#F4C430', 'aliases' => ['saffron'], 'family' => 'برتقالي وأصفر'],
            ['name' => 'خردلي', 'hex' => '#C6A13A', 'aliases' => ['mustard'], 'family' => 'برتقالي وأصفر'],
            ['name' => 'ليموني', 'hex' => '#F3E96B', 'aliases' => ['lemon'], 'family' => 'برتقالي وأصفر'],
            ['name' => 'أصفر زبدي', 'hex' => '#F6E7A9', 'aliases' => ['زبدي', 'butter yellow'], 'family' => 'برتقالي وأصفر'],
            ['name' => 'برتقالي محروق', 'hex' => '#CC6D3D', 'aliases' => ['burnt orange'], 'family' => 'برتقالي وأصفر'],
            ['name' => 'زيتي', 'hex' => '#6B7C32', 'aliases' => ['زيتوني', 'اخضر زيتي', 'olive'], 'family' => 'أخضر'],
            ['name' => 'أخضر مريمي', 'hex' => '#A3B18A', 'aliases' => ['مريمي', 'ميرمية', 'sage', 'sage green'], 'family' => 'أخضر'],
            ['name' => 'أخضر فستقي', 'hex' => '#B7CC8B', 'aliases' => ['فستقي', 'pistachio'], 'family' => 'أخضر'],
            ['name' => 'نعناعي', 'hex' => '#B7E4C7', 'aliases' => ['mint'], 'family' => 'أخضر'],
            ['name' => 'أخضر رغوي', 'hex' => '#93C9B7', 'aliases' => ['seafoam'], 'family' => 'أخضر'],
            ['name' => 'أخضر غابة', 'hex' => '#1B4332', 'aliases' => ['غابي', 'forest green'], 'family' => 'أخضر'],
            ['name' => 'أخضر زمردي', 'hex' => '#047857', 'aliases' => ['زمردي', 'emerald'], 'family' => 'أخضر'],
            ['name' => 'أخضر صنوبر', 'hex' => '#245A4A', 'aliases' => ['صنوبري', 'pine'], 'family' => 'أخضر'],
            ['name' => 'أخضر كاكي', 'hex' => '#8A8D5A', 'aliases' => ['كاكي', 'khaki'], 'family' => 'أخضر'],
            ['name' => 'أخضر طحلبي', 'hex' => '#6B7A40', 'aliases' => ['طحلبي', 'moss'], 'family' => 'أخضر'],
            ['name' => 'أخضر ليموني', 'hex' => '#A3D947', 'aliases' => ['lime'], 'family' => 'أخضر'],
            ['name' => 'أوكالبتوس', 'hex' => '#6F9F91', 'aliases' => ['اوكالبتوس', 'eucalyptus'], 'family' => 'أخضر'],
            ['name' => 'كحلي', 'hex' => '#1E3A5F', 'aliases' => ['ازرق غامق', 'أزرق داكن', 'navy', 'navy blue'], 'family' => 'أزرق وتركواز'],
            ['name' => 'سماوي', 'hex' => '#38BDF8', 'aliases' => ['ازرق فاتح', 'أزرق سماوي', 'sky blue', 'light blue'], 'family' => 'أزرق وتركواز'],
            ['name' => 'تركواز', 'hex' => '#14B8A6', 'aliases' => ['فيروزي', 'turquoise', 'teal'], 'family' => 'أزرق وتركواز'],
            ['name' => 'بترولي', 'hex' => '#1F5964', 'aliases' => ['أزرق بترولي', 'petrol'], 'family' => 'أزرق وتركواز'],
            ['name' => 'أزرق دنيم', 'hex' => '#536B8E', 'aliases' => ['دنيم', 'جينز', 'denim'], 'family' => 'أزرق وتركواز'],
            ['name' => 'أزرق كوبالت', 'hex' => '#2447AB', 'aliases' => ['كوبالت', 'cobalt'], 'family' => 'أزرق وتركواز'],
            ['name' => 'أزرق ثلجي', 'hex' => '#D9EAF3', 'aliases' => ['ثلجي', 'ice blue'], 'family' => 'أزرق وتركواز'],
            ['name' => 'أزرق باودر', 'hex' => '#B9D6E8', 'aliases' => ['باودر بلو', 'powder blue'], 'family' => 'أزرق وتركواز'],
            ['name' => 'أزرق فولاذي', 'hex' => '#607D9A', 'aliases' => ['فولاذي', 'steel blue'], 'family' => 'أزرق وتركواز'],
            ['name' => 'أزرق ملكي', 'hex' => '#3B42C4', 'aliases' => ['ملكي', 'royal blue'], 'family' => 'أزرق وتركواز'],
            ['name' => 'أزرق منتصف الليل', 'hex' => '#17233D', 'aliases' => ['midnight blue'], 'family' => 'أزرق وتركواز'],
            ['name' => 'أزرق محيطي', 'hex' => '#146C94', 'aliases' => ['محيطي', 'ocean blue'], 'family' => 'أزرق وتركواز'],
            ['name' => 'ليلكي', 'hex' => '#C4B5FD', 'aliases' => ['لافندر', 'lilac', 'lavender'], 'family' => 'بنفسجي'],
            ['name' => 'فوشي', 'hex' => '#D946EF', 'aliases' => ['فوشيا', 'fuchsia', 'magenta'], 'family' => 'بنفسجي'],
            ['name' => 'لافندر غباري', 'hex' => '#B8A1C9', 'aliases' => ['dusty lavender'], 'family' => 'بنفسجي'],
            ['name' => 'موف ترابي', 'hex' => '#A88FA8', 'aliases' => ['dusty mauve'], 'family' => 'بنفسجي'],
            ['name' => 'بنفسجي باهت', 'hex' => '#E3D6EE', 'aliases' => ['بنفسجي باستيل', 'pastel purple'], 'family' => 'بنفسجي'],
            ['name' => 'بنفسجي برقوقي', 'hex' => '#673A5E', 'aliases' => ['برقوقي', 'plum'], 'family' => 'بنفسجي'],
            ['name' => 'باذنجاني', 'hex' => '#4B2A4B', 'aliases' => ['aubergine', 'eggplant'], 'family' => 'بنفسجي'],
            ['name' => 'بنفسجي عنبي', 'hex' => '#6D3B86', 'aliases' => ['عنبي', 'grape'], 'family' => 'بنفسجي'],
            ['name' => 'أوركيد', 'hex' => '#C78BC8', 'aliases' => ['اوركيد', 'orchid'], 'family' => 'بنفسجي'],
            ['name' => 'توت أسود', 'hex' => '#51344D', 'aliases' => ['blackberry'], 'family' => 'بنفسجي'],
            ['name' => 'بنفسجي رمادي', 'hex' => '#8C819A', 'aliases' => ['purple gray'], 'family' => 'بنفسجي'],
            ['name' => 'بنفسجي إيريس', 'hex' => '#7161A9', 'aliases' => ['ايريس', 'iris'], 'family' => 'بنفسجي'],
        ];
    }

    public static function normalize(string $name): string
    {
        $name = strtr(mb_strtolower(trim($name)), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي']);

        return preg_replace('/[\s\-_\x{0640}\x{064B}-\x{065F}]+/u', '', $name);
    }

    public static function suggest(string $name): ?string
    {
        if (self::$suggestions === null) {
            self::$suggestions = [];
            foreach (self::presets() as $preset) {
                foreach ([$preset['name'], ...$preset['aliases']] as $alias) {
                    self::$suggestions[self::normalize($alias)] = $preset['hex'];
                }
            }
        }

        return self::$suggestions[self::normalize($name)] ?? null;
    }
}
