<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['name', 'code', 'address', 'city', 'state', 'pincode', 'phone', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
