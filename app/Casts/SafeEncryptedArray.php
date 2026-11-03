<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Same storage format as Laravel's `encrypted:array`, but a value that cannot be decrypted
 * (saved under a different APP_KEY, e.g. a database copied from another server) reads as
 * "not set" instead of breaking every page that touches it. Saving it again re-encrypts it.
 */
class SafeEncryptedArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return json_decode(Crypt::decryptString($value), true);
        } catch (DecryptException) {
            Log::warning("Saved {$key} for ".class_basename($model)." #{$model->getKey()} cannot be decrypted with this APP_KEY; treating it as not set. Enter it again in Settings.");

            return null;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Crypt::encryptString(json_encode($value));
    }
}
