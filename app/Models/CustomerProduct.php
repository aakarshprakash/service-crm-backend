<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
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

    /** Warranty types that mean the unit is covered when no expiry date is on record. */
    public const COVERED_TYPES = ['in_warranty', 'extended', 'amc'];

    /**
     * Covered until the expiry date when one is known. Without a date, the unit is taken at its word:
     * marked in warranty, extended or AMC means covered.
     */
    public function isUnderWarranty(): bool
    {
        if ($this->warranty_expiry !== null) {
            return $this->warranty_expiry->endOfDay()->isFuture();
        }

        return in_array($this->warranty_type, self::COVERED_TYPES, true);
    }

    /** Units covered on $today (Y-m-d where the company is): the same rule as isUnderWarranty(). */
    public function scopeUnderWarranty(Builder $query, string $today): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('warranty_expiry', '>=', $today)
            ->orWhere(fn (Builder $q) => $q->whereNull('warranty_expiry')->whereIn('warranty_type', self::COVERED_TYPES)));
    }

    /** Units known to be out of cover on $today: an expiry date that has passed, or marked out of warranty. */
    public function scopeOutOfWarranty(Builder $query, string $today): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('warranty_expiry', '<', $today)
            ->orWhere(fn (Builder $q) => $q->whereNull('warranty_expiry')->where('warranty_type', 'out_of_warranty')));
    }
}
