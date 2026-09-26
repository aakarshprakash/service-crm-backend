<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['invoice_id', 'job_visit_id', 'amount', 'method', 'receipt_number', 'reference_no', 'gateway', 'gateway_order_id', 'gateway_txn_id', 'status', 'collected_by', 'cash_close_id', 'remarks', 'paid_at'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(JobVisit::class, 'job_visit_id');
    }
}
