<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplaintSummary extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['complaint_type_id', 'name', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function complaintType(): BelongsTo
    {
        return $this->belongsTo(ComplaintType::class);
    }
}
