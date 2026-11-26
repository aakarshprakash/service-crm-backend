<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One Excel upload into SAPRE: who uploaded which file, and what it did row by row. */
class SpareImport extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'kind', 'file_name', 'lines', 'added', 'updated', 'unchanged', 'rejected', 'problems'];

    protected function casts(): array
    {
        return ['problems' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
