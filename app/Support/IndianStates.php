<?php

namespace App\Support;

/** GST state codes (first two digits of a GSTIN) and names, for place of supply. */
class IndianStates
{
    public const STATES = [
        '01' => 'Jammu and Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh',
        '05' => 'Uttarakhand', '06' => 'Haryana', '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh',
        '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur',
        '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal',
        '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh', '24' => 'Gujarat',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu', '27' => 'Maharashtra', '29' => 'Karnataka', '30' => 'Goa',
        '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu', '34' => 'Puducherry',
        '35' => 'Andaman and Nicobar Islands', '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh',
    ];

    /** State code for a name ("kerala", "KERALA ", "Tamilnadu") or a GSTIN; null when unknown. */
    public static function code(?string $nameOrGstin): ?string
    {
        $v = trim((string) $nameOrGstin);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(\d{2})[0-9A-Z]{13}$/i', $v, $m)) {
            return isset(self::STATES[$m[1]]) ? $m[1] : null;
        }
        $key = self::normalise($v);
        foreach (self::STATES as $code => $name) {
            if (self::normalise($name) === $key) {
                return $code;
            }
        }

        return null;
    }

    public static function name(?string $code): ?string
    {
        return $code ? (self::STATES[$code] ?? null) : null;
    }

    private static function normalise(string $v): string
    {
        return preg_replace('/[^a-z]/', '', str_replace('&', 'and', strtolower($v)));
    }
}
