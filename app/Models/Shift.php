<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['name', 'start_time', 'end_time', 'grace_minutes', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return [
            'grace_minutes' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // One company default: choosing a new one replaces the old.
        static::saved(function (Shift $shift) {
            if ($shift->is_default) {
                static::query()->whereKeyNot($shift->id)->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }
}
