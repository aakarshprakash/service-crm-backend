<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianStock extends Model
{
    use BelongsToTenant;

    protected $table = 'technician_stock';

    protected $fillable = ['technician_id', 'item_id', 'branch_id', 'quantity'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
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
}
