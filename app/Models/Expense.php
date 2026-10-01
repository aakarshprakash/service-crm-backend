<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use Auditable, BelongsToTenant;

    public const METHODS = ['cash', 'upi', 'cheque', 'bank_transfer'];

    protected $fillable = [
        'expense_date', 'expense_category_id', 'amount', 'payment_method', 'paid_to', 'reference_no',
        'description', 'branch_id', 'user_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date:Y-m-d',
            'amount' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
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
