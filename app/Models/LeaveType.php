<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['name', 'code', 'annual_quota', 'is_paid', 'is_active'];

    protected function casts(): array
    {
        return [
            'annual_quota' => 'float',
            'is_paid' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
