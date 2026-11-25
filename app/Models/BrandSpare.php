<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One part line the brand billed to the service centre, and where it is now.
 *
 * The stage is never set by hand. It follows from what has been filled in:
 *   unsorted – nobody has said yet whether it is warranty or out of warranty
 *   inspect  – warranty part used on a job; the faulty part still needs an inspection number
 *   ready    – inspected (or a fresh part to send back); waiting for the courier
 *   sent     – handed to the courier (LR number or date)
 *   closed   – nothing to return: out of warranty, bought for stock, or charged to us as OW
 */
class BrandSpare extends Model
{
    use Auditable, BelongsToTenant;

    public const COVERAGES = ['warranty', 'out_of_warranty', 'stock'];

    public const STAGES = ['unsorted', 'inspect', 'ready', 'sent', 'closed'];

    public const RETURN_TYPES = ['defective', 'fresh'];

    protected $fillable = [
        'brand_id', 'billing_doc', 'billing_date', 'material_code', 'description', 'quantity', 'value', 'delivery_no', 'po_number',
        'ticket_no', 'customer_name', 'coverage', 'return_type', 'inspection_no', 'rma_no', 'lr_number', 'lr_date', 'courier',
        'ow_billed', 'ow_billed_on', 'ow_bill_no', 'company_pending', 'company_checked_on', 'remarks', 'import_key', 'created_by',
    ];

    protected $hidden = ['import_key'];

    protected function casts(): array
    {
        return [
            'billing_date' => 'date:Y-m-d',
            'lr_date' => 'date:Y-m-d',
            'ow_billed_on' => 'date:Y-m-d',
            'company_checked_on' => 'date:Y-m-d',
            'quantity' => 'float',
            'value' => 'integer',
            'ow_billed' => 'boolean',
            'company_pending' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (BrandSpare $spare) {
            $spare->stage = self::stageFor($spare->only(['coverage', 'ow_billed', 'lr_number', 'lr_date', 'inspection_no', 'rma_no', 'return_type']));
        });
    }

    /** The stage for a set of fields; also used by the importer, which inserts rows in bulk. */
    public static function stageFor(array $f): string
    {
        $filled = fn (string $k) => isset($f[$k]) && $f[$k] !== '' && $f[$k] !== null;

        return match (true) {
            ! $filled('coverage') => 'unsorted',
            $f['coverage'] !== 'warranty', ! empty($f['ow_billed']) => 'closed',
            $filled('lr_number') || $filled('lr_date') => 'sent',
            $filled('inspection_no') || $filled('rma_no') || ($f['return_type'] ?? null) === 'fresh' => 'ready',
            default => 'inspect',
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

    /**
     * Identifies one billed line: the n-th copy of a part on a billing document. Uploads and lines
     * added by hand share it, so the same line is never added twice.
     */
    public static function importKey(string $doc, string $material, float $quantity, int $value, int $n = 1): string
    {
        return sha1(strtolower(implode('|', [trim($doc), trim($material), $quantity, $value, $n])));
    }
}
