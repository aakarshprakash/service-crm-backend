<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum writes last_used_at on every API request, a database write per call. It is only read in days
 * (idle sign-out) and shown in the sessions list, so once a minute is plenty: a save that would only move
 * last_used_at by less than that is skipped.
 */
class PersonalAccessToken extends SanctumToken
{
    public const TOUCH_EVERY_SECONDS = 60;

    protected $table = 'personal_access_tokens';

    public function save(array $options = []): bool
    {
        $dirty = array_keys($this->getDirty());
        $onlyTouch = $this->exists && $dirty && ! array_diff($dirty, ['last_used_at', 'updated_at']);
        $previous = $this->getOriginal('last_used_at');
        if ($onlyTouch && $previous && $this->last_used_at && abs($this->last_used_at->diffInSeconds($previous)) < self::TOUCH_EVERY_SECONDS) {
            $this->syncOriginal();

            return true;
        }

        return parent::save($options);
    }
}
