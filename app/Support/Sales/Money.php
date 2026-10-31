<?php

namespace App\Support\Sales;

use Brick\Math\BigInteger;
use Brick\Math\Exception\IntegerOverflowException;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class Money
{
    public static function minor(string|int|float $value): int
    {
        if (! preg_match('/\A\d{1,12}(?:\.\d{1,2})?\z/', (string) $value)) {
            throw ValidationException::withMessages(['amount' => 'أدخل مبلغًا صالحًا بمنزلتين عشريتين كحد أقصى.']);
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function integer(BigInteger|string|int $value): int
    {
        try {
            return BigInteger::of($value)->toInt();
        } catch (IntegerOverflowException $exception) {
            throw ValidationException::withMessages(['amount' => 'القيمة تتجاوز النطاق المالي المدعوم. يلزم مراجعة المستند أو الرصيد دون تقريبه.']);
        }
    }

    public static function add(int $first, int $second): int
    {
        return self::integer(BigInteger::of($first)->plus($second));
    }

    public static function multiply(int $first, int $second): int
    {
        return self::integer(BigInteger::of($first)->multipliedBy($second));
    }

    /** @param iterable<int> $values */
    public static function sum(iterable $values): int
    {
        $total = BigInteger::zero();
        foreach ($values as $value) {
            $total = $total->plus($value);
        }

        return self::integer($total);
    }

    public static function format(int $minor): string
    {
        $absolute = abs($minor);

        return ($minor < 0 ? '-' : '').intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function ratio(int $value, int $numerator, int $denominator): int
    {
        if ($denominator <= 0 || $value < 0 || $numerator < 0) {
            throw ValidationException::withMessages(['amount' => 'بيانات الحساب أو التكلفة غير صالحة.']);
        }

        return self::integer(BigInteger::of($value)->multipliedBy($numerator)->dividedBy($denominator, RoundingMode::HalfUp));
    }

    /** @param list<int> $weights @return list<int> */
    public static function allocate(int $amount, array $weights): array
    {
        $total = self::sum($weights);
        if ($amount === 0) {
            return array_fill(0, count($weights), 0);
        }
        if ($total <= 0 || $amount > $total || min($weights) < 0) {
            throw ValidationException::withMessages(['discount' => 'الخصم يتجاوز قيمة الأصناف.']);
        }
        $shares = $remainders = [];
        foreach ($weights as $index => $weight) {
            [$share, $remainder] = BigInteger::of($amount)->multipliedBy($weight)->quotientAndRemainder($total);
            $shares[$index] = $share->toInt();
            $remainders[$index] = $remainder->toInt();
        }
        arsort($remainders, SORT_NUMERIC);
        $left = $amount - array_sum($shares);
        foreach (array_keys($remainders) as $index) {
            if ($left-- <= 0) {
                break;
            }
            $shares[$index]++;
        }
        ksort($shares);

        return array_values($shares);
    }

    /** @param array{type?: string, value?: string|int} $discount */
    public static function discount(int $gross, array $discount): int
    {
        $value = self::minor($discount['value'] ?? '0');
        if (($discount['type'] ?? 'amount') === 'percent') {
            if ($value > 10000) {
                throw ValidationException::withMessages(['discount' => 'نسبة الخصم يجب ألا تتجاوز 100%.']);
            }

            return self::ratio($gross, $value, 10000);
        }
        if ($value > $gross) {
            throw ValidationException::withMessages(['discount' => 'الخصم يتجاوز قيمة الأصناف.']);
        }

        return $value;
    }
}
