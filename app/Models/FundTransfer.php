<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Contra entry: cash deposited into the bank, or cash withdrawn from it. */
class FundTransfer extends Model
{
    use Auditable, BelongsToTenant;

    public const DIRECTIONS = ['cash_to_bank', 'bank_to_cash'];

    protected $fillable = ['transfer_date', 'direction', 'amount', 'reference_no', 'notes', 'created_by'];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date:Y-m-d',
            'amount' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
