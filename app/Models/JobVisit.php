<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobVisit extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = [
        'job_id', 'technician_id', 'visit_date', 'service_type', 'start_time', 'end_time', 'duration_seconds', 'status',
        'action_taken_id', 'service_summary', 'assisted_staff_id', 'labour_charge', 'spare_charge', 'total_charge',
        'payment_method', 'amount_collected', 'location_lat', 'location_lng', 'end_lat', 'end_lng', 'signer_name', 'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date:Y-m-d',
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'signed_at' => 'datetime',
            'labour_charge' => 'integer',
            'spare_charge' => 'integer',
            'total_charge' => 'integer',
            'amount_collected' => 'integer',
            'location_lat' => 'float',
            'location_lng' => 'float',
            'end_lat' => 'float',
            'end_lng' => 'float',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === 'in_progress';
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'job_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function assistedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assisted_staff_id');
    }

    public function actionTaken(): BelongsTo
    {
        return $this->belongsTo(ActionTakenOption::class, 'action_taken_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(JobImage::class, 'job_visit_id');
    }

    public function inventoryUsage(): HasMany
    {
        return $this->hasMany(JobInventoryUsage::class, 'job_visit_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'job_visit_id');
    }
}
