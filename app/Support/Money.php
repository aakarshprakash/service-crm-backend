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

    /** "Rupees One Lakh Twenty Thousand and Fifty Paise Only" – Indian numbering (lakh, crore). */
    public static function inWords(int $minor, string $currency = 'INR'): string
    {
        [$unit, $sub] = $currency === 'INR' ? ['Rupees', 'Paise'] : [$currency, 'Cents'];
        $major = intdiv(abs($minor), 100);
        $paise = abs($minor) % 100;
        $words = $unit.' '.($major === 0 ? 'Zero' : self::indianWords($major));
        if ($paise > 0) {
            $words .= ' and '.self::indianWords($paise).' '.$sub;
        }

        return ($minor < 0 ? 'Minus ' : '').$words.' Only';
    }

    private static function indianWords(int $n): string
    {
        $parts = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$size, $name]) {
            if ($n >= $size) {
                $parts[] = ($size === 10000000 ? self::indianWords(intdiv($n, $size)) : self::belowHundred(intdiv($n, $size))).' '.$name;
                $n %= $size;
            }
        }
        if ($n > 0) {
            $parts[] = self::belowHundred($n);
        }

        return implode(' ', $parts);
    }

    private static function belowHundred(int $n): string
    {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen',
            'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        return $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)].' '.$ones[$n % 10]);
    }
}
