<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStock extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_stock';

    protected $fillable = ['branch_id', 'item_id', 'quantity_available', 'avg_unit_cost', 'low_stock_alerted_at'];

    protected function casts(): array
    {
        return [
            'quantity_available' => 'float',
            'avg_unit_cost' => 'integer',
            'low_stock_alerted_at' => 'datetime',
        ];
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
