<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * Coordinates from what customers actually send: a WhatsApp / Google Maps location
 * link, a maps.app.goo.gl short link, an Apple Maps link or plain "12.97, 77.59".
 * Short links are expanded by following redirects, but only between known map hosts
 * (never an arbitrary URL from user input).
 */
final class GeoLink
{
    private const HOSTS = [
        'maps.app.goo.gl', 'goo.gl', 'g.co', 'maps.google.com', 'google.com', 'www.google.com', 'maps.google.co.in',
        'www.google.co.in', 'google.co.in', 'maps.apple.com', 'consent.google.com',
    ];

    /** @return array{lat: float, lng: float}|null */
    public static function resolve(string $input): ?array
    {
        $input = trim($input);
        if ($found = self::parse($input)) {
            return $found;
        }
        if (! preg_match('#^https?://#i', $input)) {
            return null;
        }

        $url = $input;
        for ($hop = 0; $hop < 5; $hop++) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! in_array($host, self::HOSTS, true)) {
                return null;
            }
            try {
                $response = Http::withoutRedirecting()->timeout(6)->withHeaders(['User-Agent' => 'Mozilla/5.0 (Servon location link)'])->get($url);
            } catch (\Throwable) {
                return null;
            }
            $next = $response->header('Location');
            if (! $next) {
                // Final page: Google embeds the coordinates in the body when the URL has none.
                return self::parse($url) ?? self::parse(mb_substr($response->body(), 0, 200000));
            }
            if (str_starts_with($next, '/')) {
                $next = parse_url($url, PHP_URL_SCHEME).'://'.$host.$next;
            }
            // Cookie-consent interstitial: the real destination is in ?continue=
            parse_str((string) parse_url($next, PHP_URL_QUERY), $query);
            if (! empty($query['continue']) && is_string($query['continue'])) {
                $next = $query['continue'];
            }
            if ($found = self::parse(urldecode($next))) {
                return $found;
            }
            $url = $next;
        }

        return null;
    }

    /** @return array{lat: float, lng: float}|null */
    public static function parse(string $text): ?array
    {
        $num = '(-?\d{1,3}(?:\.\d+)?)';
        $patterns = [
            '/!3d'.$num.'!4d'.$num.'/',                                         // place pin in /maps/place/... URLs
            '/[?&](?:q|query|ll|destination|daddr|center|sll)=(?:loc:)?'.$num.'\s*(?:,|%2C)\s*'.$num.'/i',
            '/@'.$num.','.$num.'/',                                              // map viewport
            '/^\s*'.$num.'\s*,\s*'.$num.'\s*$/',                                 // plain "lat, lng"
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];
                if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && ! ($lat == 0.0 && $lng == 0.0)) {
                    return ['lat' => round($lat, 7), 'lng' => round($lng, 7)];
                }
            }
        }

        return null;
    }
}
