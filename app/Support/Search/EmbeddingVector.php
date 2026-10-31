<?php

namespace App\Support\Search;

use InvalidArgumentException;

class EmbeddingVector
{
    /** @param list<float|int> $values
     * @return list<float>
     */
    public static function normalize(array $values, int $dimensions = 512): array
    {
        if (! array_is_list($values) || count($values) !== $dimensions || $dimensions < 1 || $dimensions > 4096) {
            throw new InvalidArgumentException('Invalid embedding dimensions.');
        }
        $sum = 0.0;
        foreach ($values as $value) {
            if ((! is_float($value) && ! is_int($value)) || ! is_finite((float) $value)) {
                throw new InvalidArgumentException('Invalid embedding value.');
            }
            $sum += $value * $value;
        }
        if (! is_finite($sum) || $sum <= 0) {
            throw new InvalidArgumentException('Empty embedding norm.');
        }
        $norm = sqrt($sum);

        return array_map(fn (float|int $value): float => $value / $norm, $values);
    }

    /** @param list<float> $values */
    public static function encode(array $values): string
    {
        return pack('g*', ...self::normalize($values));
    }

    /** @return list<float> */
    public static function decode(string $payload, int $dimensions): array
    {
        if (strlen($payload) !== $dimensions * 4) {
            throw new InvalidArgumentException('Invalid embedding payload.');
        }

        return self::normalize(array_values(unpack('g*', $payload)), $dimensions);
    }

    /** @param list<float> $left
     * @param  list<float>  $right
     */
    public static function cosine(array $left, array $right): float
    {
        if (count($left) !== count($right)) {
            throw new InvalidArgumentException('Incompatible embeddings.');
        }
        $score = 0.0;
        foreach ($left as $index => $value) {
            $score += $value * $right[$index];
        }

        return max(-1.0, min(1.0, $score));
    }
}
