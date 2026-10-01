<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ServiceLocation extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['name', 'code', 'city', 'pincodes', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /** @return list<string> */
    public function pincodeList(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $this->pincodes))));
    }

    /** Store PIN codes normalised: digits only, de-duplicated, comma separated. */
    public function setPincodesAttribute(?string $value): void
    {
        $codes = array_unique(array_filter(array_map(fn ($c) => preg_replace('/\D/', '', $c), preg_split('/[\s,]+/', (string) $value))));
        $this->attributes['pincodes'] = $codes ? implode(', ', $codes) : null;
    }
}
