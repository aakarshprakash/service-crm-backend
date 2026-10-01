<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Asset extends Model
{
    use Auditable, BelongsToTenant;

    public const CATEGORIES = ['tool', 'vehicle', 'device', 'equipment', 'other'];

    public const STATUSES = ['available', 'assigned', 'under_repair', 'lost', 'retired'];

    public const CONDITIONS = ['new', 'good', 'fair', 'poor', 'damaged'];

    protected $fillable = [
        'asset_code', 'name', 'category', 'brand', 'model', 'serial_no', 'purchase_date', 'purchase_cost',
        'warranty_expiry', 'branch_id', 'status', 'condition', 'assigned_to', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date:Y-m-d',
            'warranty_expiry' => 'date:Y-m-d',
            'purchase_cost' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class)->latest('issued_at')->latest('id');
    }

    public function currentAssignment(): HasOne
    {
        // At most one assignment is open at a time (issue requires the asset to be available).
        return $this->hasOne(AssetAssignment::class)->whereNull('returned_at');
    }
}
