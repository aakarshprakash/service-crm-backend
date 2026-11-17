<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\SignedUrl;

/** A voice note recorded by the technician during a visit (private disk, signed URL). */
class JobVoiceNote extends Model
{
    use BelongsToTenant;

    protected $fillable = ['job_id', 'job_visit_id', 'file_path', 'mime', 'duration_seconds', 'size', 'uploaded_by'];

    protected $hidden = ['file_path'];

    protected $appends = ['url'];

    protected function casts(): array
    {
        return ['duration_seconds' => 'integer', 'size' => 'integer'];
    }

    public function getUrlAttribute(): ?string
    {
        return $this->id ? SignedUrl::stable('files.voice', ['note' => $this->id], 60) : null;
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(JobVisit::class, 'job_visit_id');
    }
}
