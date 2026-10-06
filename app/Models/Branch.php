<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['name', 'code', 'address', 'city', 'state', 'pincode', 'phone', 'lat', 'lng', 'geofence_radius', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'lat' => 'float',
            'lng' => 'float',
            'geofence_radius' => 'integer',
        ];
    }

    public function hasGeofence(): bool
    {
        return $this->lat !== null && $this->lng !== null && $this->geofence_radius > 0;
    }
}
