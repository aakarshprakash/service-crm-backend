<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money received without an invoice (manual income). It goes into the cash or bank book by its method. */
class Income extends Model
{
    use Auditable, BelongsToTenant;

    public const METHODS = ['cash', 'upi', 'cheque', 'bank_transfer'];

    protected $fillable = [
        'income_date', 'income_category_id', 'amount', 'payment_method', 'received_from', 'phone', 'reference_no',
        'description', 'branch_id', 'user_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'income_date' => 'date:Y-m-d',
            'amount' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(IncomeCategory::class, 'income_category_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
