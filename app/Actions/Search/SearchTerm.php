<?php

namespace App\Actions\Search;

use Illuminate\Database\Eloquent\Builder;

class SearchTerm
{
    public static function normalize(?string $value): string
    {
        return preg_replace('/\\s+/u', ' ', trim(strtr($value ?? '', array_combine(
            preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789')))));
    }

    /** @param list<string> $textColumns @param list<string> $codeColumns */
    public static function apply(Builder $query, string $term, array $textColumns, array $codeColumns = [], string $mode = 'contains'): Builder
    {
        $term = self::normalize($term);
        if ($term === '') {
            return $query;
        }
        $mode = mb_strlen($term) < 2 ? 'exact' : $mode;

        return $query->where(function (Builder $nested) use ($term, $textColumns, $codeColumns, $mode): void {
            foreach (array_merge($textColumns, $codeColumns) as $column) {
                $wrapped = $nested->getQuery()->getGrammar()->wrap($column);
                if ($mode === 'exact') {
                    $nested->orWhere($column, $term);
                } else {
                    $nested->orWhereRaw("$wrapped LIKE ? ESCAPE '!'", [self::pattern($term, $mode)]);
                }
            }
        });
    }

    public static function pattern(string $term, string $mode = 'contains'): string
    {
        $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], self::normalize($term));

        return ($mode === 'prefix' ? '' : '%').$literal.'%';
    }

    public static function documentId(string $term, string $prefix): ?int
    {
        if (! preg_match('/\\A(?:'.preg_quote($prefix, '/').'-)?0*([1-9][0-9]{0,17})\\z/i', $term, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }
}
