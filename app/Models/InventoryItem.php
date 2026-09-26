<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['code', 'name', 'type', 'category', 'unit_of_measure', 'unit_price', 'reorder_level', 'is_active'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'reorder_level' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function stock(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'item_id');
    }
}
