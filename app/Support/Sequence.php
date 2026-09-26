<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Concurrency-safe per-tenant counters. Must be called inside a transaction so the
 * row lock is held until the number is persisted.
 */
class Sequence
{
    public static function next(int $tenantId, string $name): int
    {
        return DB::transaction(function () use ($tenantId, $name) {
            DB::table('sequences')->insertOrIgnore(['tenant_id' => $tenantId, 'name' => $name, 'value' => 0]);
            $row = DB::table('sequences')->where(['tenant_id' => $tenantId, 'name' => $name])->lockForUpdate()->first();
            $value = $row->value + 1;
            DB::table('sequences')->where('id', $row->id)->update(['value' => $value]);

            return $value;
        });
    }

    /** e.g. SC-2609-00042 (prefix-YYMM-counter). */
    public static function formatted(int $tenantId, string $name, string $prefix, int $pad = 5): string
    {
        return sprintf('%s-%s-%s', $prefix, now()->format('ym'), str_pad((string) self::next($tenantId, $name), $pad, '0', STR_PAD_LEFT));
    }
}
