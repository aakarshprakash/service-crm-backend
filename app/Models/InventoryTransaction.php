<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransaction extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['branch_id', 'item_id', 'type', 'quantity', 'balance_after', 'unit_cost', 'supplier_id', 'invoice_ref', 'reference_type', 'reference_id', 'remarks', 'created_by'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'balance_after' => 'float',
            'unit_cost' => 'integer',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
