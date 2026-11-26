<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One part line the brand billed to the service centre, and where it is now.
 *
 * The status is never set by hand. It follows from what has been filled in:
 *
 *   unsorted              nobody has said yet whether it is warranty or out of warranty
 *   Warranty (blue):
 *   pending_inspection    the faulty part still needs the brand's inspection number
 *   pending_dispatch      inspected (or a fresh part going back), not yet with the courier
 *   pending_return        with the courier; Lloyd has not confirmed it received the part
 *   return_completed      Lloyd received it
 *   replacement_completed Lloyd received it and a replacement part was issued
 *   Out of warranty, and warranty parts Lloyd charged as OW:
 *   ow_billing_pending    marked OW (orange); Lloyd has not billed it yet
 *   payment_pending       Lloyd billed it (light blue); not paid yet
 *   completed             paid (grey), or a part bought for stock: nothing left to do
 */
class BrandSpare extends Model
{
    use Auditable, BelongsToTenant;

    public const COVERAGES = ['warranty', 'out_of_warranty', 'stock'];

    public const RETURN_TYPES = ['defective', 'fresh'];

    public const OW_STAGES = ['marked', 'billed', 'paid'];

    public const STATUSES = [
        'unsorted', 'pending_inspection', 'pending_dispatch', 'pending_return', 'return_completed', 'replacement_completed',
        'ow_billing_pending', 'payment_pending', 'completed',
    ];

    public const PENDING = ['unsorted', 'pending_inspection', 'pending_dispatch', 'pending_return', 'ow_billing_pending', 'payment_pending'];

    public const DONE = ['return_completed', 'replacement_completed', 'completed'];

    protected $fillable = [
        'brand_id', 'billing_doc', 'billing_date', 'material_code', 'description', 'quantity', 'value', 'delivery_no', 'po_number',
        'received_on', 'ticket_no', 'customer_name', 'coverage', 'return_type', 'inspection_no', 'inspection_on', 'rma_no', 'rma_on',
        'lr_number', 'lr_date', 'courier', 'return_received_on', 'replacement_doc', 'replacement_material', 'replacement_on',
        'ow_billed', 'ow_stage', 'ow_billed_on', 'ow_bill_no', 'ow_paid_on', 'ow_payment_ref',
        'company_pending', 'company_checked_on', 'remarks', 'import_key', 'created_by',
    ];

    protected $hidden = ['import_key'];

    protected function casts(): array
    {
        $date = 'date:Y-m-d';

        return [
            'billing_date' => $date, 'received_on' => $date, 'inspection_on' => $date, 'rma_on' => $date, 'lr_date' => $date,
            'return_received_on' => $date, 'replacement_on' => $date, 'ow_billed_on' => $date, 'ow_paid_on' => $date,
            'company_checked_on' => $date,
            'quantity' => 'float',
            'value' => 'integer',
            'ow_billed' => 'boolean',
            'company_pending' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (BrandSpare $spare) {
            $spare->status = self::statusFor($spare->getAttributes());
        });
    }

    /** True when the part is on the OW path: out of warranty, or a warranty part Lloyd charged to us. */
    public static function onOwPath(array $f): bool
    {
        return ($f['coverage'] ?? null) === 'out_of_warranty' || ! empty($f['ow_billed']);
    }

    /** The status for a set of fields; also used by the importer, which inserts rows in bulk. */
    public static function statusFor(array $f): string
    {
        $filled = fn (string $k) => isset($f[$k]) && $f[$k] !== '' && $f[$k] !== null;
        $coverage = $f['coverage'] ?? null;

        if (! $filled('coverage')) {
            return 'unsorted';
        }
        if ($coverage === 'stock') {
            return 'completed';
        }
        if (self::onOwPath($f)) {
            // A warranty part Lloyd charged to us has been billed even when no stage was given.
            $stage = $f['ow_stage'] ?? (! empty($f['ow_billed']) ? 'billed' : 'marked');

            return match ($stage) {
                'paid' => 'completed',
                'billed' => 'payment_pending',
                default => 'ow_billing_pending',
            };
        }

        return match (true) {
            $filled('return_received_on') => $filled('replacement_doc') || $filled('replacement_on') ? 'replacement_completed' : 'return_completed',
            $filled('lr_number') || $filled('lr_date') => 'pending_return',
            $filled('inspection_no') || $filled('rma_no') || ($f['return_type'] ?? null) === 'fresh' => 'pending_dispatch',
            default => 'pending_inspection',
        };
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * The job booked with this ticket as its External CRM call ID. Looked up by number rather than
     * stored, so a job booked after the bill was uploaded is found without anyone relinking it.
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'ticket_no', 'crm_call_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Identifies one billed line: the n-th copy of a part on a billing document. Uploads and lines
     * added by hand share it, so the same line is never added twice.
     */
    public static function importKey(string $doc, string $material, float $quantity, int $value, int $n = 1): string
    {
        return sha1(strtolower(implode('|', [trim($doc), trim($material), $quantity, $value, $n])));
    }
}
