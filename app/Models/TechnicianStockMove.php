<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianStockMove extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['technician_id', 'item_id', 'branch_id', 'type', 'quantity', 'balance_after', 'job_id', 'job_visit_id', 'remarks', 'created_by'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'balance_after' => 'float',
        ];
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'job_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
