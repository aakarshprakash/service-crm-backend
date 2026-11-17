<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line on an estimate: a service charge, or a part from the price list. */
class EstimateItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['estimate_id', 'type', 'item_id', 'description', 'quantity', 'unit_price', 'total', 'position'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_price' => 'integer',
            'total' => 'integer',
            'position' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }
}
