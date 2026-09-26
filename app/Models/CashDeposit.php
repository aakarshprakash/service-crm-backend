<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashDeposit extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['cash_close_id', 'technician_id', 'amount', 'deposited_to', 'reference_no', 'deposit_date', 'remarks', 'recorded_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'deposit_date' => 'date:Y-m-d',
        ];
    }

    public function cashClose(): BelongsTo
    {
        return $this->belongsTo(CashClose::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
