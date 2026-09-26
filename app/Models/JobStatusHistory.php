<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobStatusHistory extends Model
{
    use BelongsToTenant;

    protected $table = 'job_status_history';

    public $timestamps = false;

    protected $fillable = ['job_id', 'status', 'changed_by', 'remarks', 'changed_at'];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
