<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PunchLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'type', 'lat', 'lng'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
