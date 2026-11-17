<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\SignedUrl;

class JobImage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['job_id', 'job_visit_id', 'type', 'file_path', 'original_name', 'size', 'uploaded_by'];

    protected $hidden = ['file_path'];

    protected $appends = ['url'];

    /** Short-lived signed URL; images live on a private disk, never a public bucket. Stable for a while, so devices cache the photo. */
    public function getUrlAttribute(): ?string
    {
        return $this->id ? SignedUrl::stable('files.image', ['image' => $this->id], 30) : null;
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'job_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(JobVisit::class, 'job_visit_id');
    }
}
