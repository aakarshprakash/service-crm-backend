<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use BelongsToTenant;

    protected $fillable = ['job_id', 'customer_id', 'technician_id', 'rating', 'comment'];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'job_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
