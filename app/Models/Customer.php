<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $fillable = ['branch_id', 'crm_id', 'name', 'phone', 'alt_phone', 'email', 'address', 'city', 'state', 'pincode', 'lat', 'lng'];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(CustomerProduct::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(ServiceJob::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
