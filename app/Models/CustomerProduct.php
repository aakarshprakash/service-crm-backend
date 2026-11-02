<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProduct extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['customer_id', 'product_id', 'model_no', 'brand_id', 'category_id', 'serial_no', 'outdoor_serial_no', 'purchase_date', 'warranty_type', 'warranty_expiry', 'dealer_id'];

    protected static function booted(): void
    {
        // Once the exact model is known, its brand and product type are authoritative.
        static::saving(function (CustomerProduct $cp) {
            if ($cp->product_id && $cp->isDirty('product_id') && ($model = Product::find($cp->product_id))) {
                $cp->brand_id = $model->brand_id ?? $cp->brand_id;
                $cp->category_id = $model->category_id ?? $cp->category_id;
                $cp->model_no = $model->model_name;
            } elseif ($cp->isDirty('model_no') && $cp->product_id) {
                // Typed text now says what the model is, so the old master-list link no longer applies.
                $cp->product_id = null;
            }
        });
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

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
