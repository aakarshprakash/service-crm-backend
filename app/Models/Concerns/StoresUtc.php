<?php

namespace App\Models\Concerns;

/**
 * Timestamps arrive from clients as ISO strings, sometimes with an offset
 * ("2026-10-15T15:30:00+05:30"). Eloquent formats the parsed date in that offset,
 * so without this it would be stored as 15:30 UTC: 5½ hours late. Convert to the
 * app's timezone (UTC) before saving.
 */
trait StoresUtc
{
    public function fromDateTime($value)
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return $this->asDateTime($value)->setTimezone(config('app.timezone'))->format($this->getDateFormat());
    }
}
