<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProduct extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['customer_id', 'product_id', 'serial_no', 'outdoor_serial_no', 'purchase_date', 'warranty_type', 'warranty_expiry', 'dealer_id'];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date:Y-m-d',
            'warranty_expiry' => 'date:Y-m-d',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function isUnderWarranty(): bool
    {
        return $this->warranty_expiry !== null && $this->warranty_expiry->endOfDay()->isFuture();
    }
}
