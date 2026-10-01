<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line on a walk-in bill: a service charge or a part sold from stock. */
class InvoiceItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['invoice_id', 'type', 'item_id', 'description', 'quantity', 'unit_price', 'total'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_price' => 'integer',
            'total' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }
}
