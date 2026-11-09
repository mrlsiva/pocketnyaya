<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money as whole paise, so no calculation ever goes through a float.
 */
final class Money
{
    public static function toPaise(string|int|float $amount): int
    {
        $text = is_float($amount) ? number_format($amount, 2, '.', '') : trim((string) $amount);
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $text, $m) !== 1) {
            throw new InvalidArgumentException("Not a valid amount: {$text}");
        }

        $paise = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return $m[1] === '-' ? -$paise : $paise;
    }

    public static function fromPaise(int $paise): string
    {
        $sign = $paise < 0 ? '-' : '';
        $paise = abs($paise);

        return sprintf('%s%d.%02d', $sign, intdiv($paise, 100), $paise % 100);
    }

    /** A percentage of an amount, rounded half up. A rate like 9.00 is held as 900. */
    public static function percentOf(int $paise, string|int|float $rate): int
    {
        return intdiv($paise * self::toPaise($rate) + 5000, 10000);
    }

    /** quantity (up to 2 decimals) × unit price, rounded half up. */
    public static function multiply(string|int|float $quantity, int $unitPaise): int
    {
        return intdiv(self::toPaise($quantity) * $unitPaise + 50, 100);
    }

    /** Indian digit grouping for display, such as 1,25,000.50 */
    public static function format(string|int|float $amount): string
    {
        $paise = self::toPaise($amount);
        [$whole, $fraction] = explode('.', self::fromPaise(abs($paise)));
        $grouped = strlen($whole) > 3
            ? (string) preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($whole, 0, -3)).','.substr($whole, -3)
            : $whole;

        return ($paise < 0 ? '-' : '').'₹'.$grouped.'.'.$fraction;
    }
}
