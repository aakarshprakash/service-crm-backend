<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobInventoryUsage extends Model
{
    use BelongsToTenant;

    protected $table = 'job_inventory_usage';

    protected $fillable = ['job_id', 'job_visit_id', 'item_id', 'branch_id', 'quantity', 'unit_price', 'total_price'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_price' => 'integer',
            'total_price' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(JobVisit::class, 'job_visit_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'job_id');
    }
}
