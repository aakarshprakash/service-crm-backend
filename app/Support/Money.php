<?php

namespace App\Support;

/**
 * Money is stored as integers in the smallest currency unit (paise / cents).
 */
class Money
{
    public static function format(int $minor, string $currency = 'INR'): string
    {
        $symbols = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'AED '];

        return ($symbols[$currency] ?? $currency.' ').number_format($minor / 100, 2);
    }

    public static function toMinor(float|int|string|null $major): int
    {
        return (int) round(((float) $major) * 100);
    }
}
