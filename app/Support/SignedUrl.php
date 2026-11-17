<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * Signed file links that stay the same for a while.
 *
 * A link signed with "now + 30 minutes" is different on every request, so each refresh of a job handed the
 * phone or browser brand-new addresses for the same photos and they were downloaded again every time.
 * Here the expiry is rounded to a fixed block, so everyone asking within the same block gets the same link
 * and the photo comes from the device's cache. A link is valid for at least $minutes and at most twice that.
 */
class SignedUrl
{
    public static function stable(string $route, array $parameters, int $minutes = 30): string
    {
        $block = $minutes * 60;
        $expires = (intdiv(now()->getTimestamp(), $block) + 2) * $block;

        // A date, not a bare number: the framework would read a number as "this many seconds from now".
        return URL::temporarySignedRoute($route, \Illuminate\Support\Carbon::createFromTimestamp($expires), $parameters);
    }
}
