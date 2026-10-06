<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A UPI ID (VPA) the company collects payments into. */
class UpiAccount extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['name', 'vpa', 'payee_name', 'branch_id', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Only one default per company.
        static::saved(function (UpiAccount $account) {
            if ($account->is_default) {
                static::where('id', '!=', $account->id)->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** The account to collect into for a branch: its own default, else the company default, else any active one. */
    public static function forBranch(?int $branchId): ?self
    {
        $active = static::where('is_active', true);

        return ($branchId ? (clone $active)->where('branch_id', $branchId)->orderByDesc('is_default')->first() : null)
            ?? (clone $active)->where('is_default', true)->first()
            ?? (clone $active)->orderBy('id')->first();
    }

    /**
     * NPCI UPI deep link (what the QR code encodes). Any UPI app can scan it.
     *
     * @param  int|null  $amountMinor  amount in paise; null lets the payer type it
     */
    public function link(?int $amountMinor = null, ?string $note = null, string $currency = 'INR'): string
    {
        $params = ['pa' => $this->vpa, 'pn' => $this->payee_name];
        if ($amountMinor) {
            $params['am'] = number_format($amountMinor / 100, 2, '.', '');
        }
        $params['cu'] = $currency;
        if ($note) {
            $params['tn'] = mb_substr($note, 0, 80);
        }

        return 'upi://pay?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
