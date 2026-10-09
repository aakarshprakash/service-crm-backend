<?php

namespace App\Services;

use App\Models\CashClose;
use App\Models\CashDeposit;
use App\Models\ExpenseClaim;
use App\Models\JobVisit;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daily cash close (§5.15).
 *
 * Offline collections by a service agent stay "unclosed" until the agent submits a
 * close. A close for date D covers every unclosed collection up to the end of D
 * (tenant timezone). Cash and cheques must be handed over; UPI / bank transfers are
 * listed for verification only. Undeposited cash carries forward as cash-in-hand.
 * Credit sales never enter the cash close (FR-15.6). Expense claims the technician
 * paid from collected cash (not rejected) are deducted from what must be handed over.
 */
class CashCloseService
{
    private const IN_HAND = ['cash', 'cheque'];

    private const DIGITAL = ['upi', 'bank_transfer'];

    /** FR-15.1: auto-computed summary for a technician and date. */
    public function summary(User $technician, string $date): array
    {
        $tenant = Tenant::findOrFail($technician->tenant_id);
        $existing = CashClose::where('technician_id', $technician->id)->whereDate('close_date', $date)
            ->with(['deposits', 'verifier:id,name'])->first();

        $payments = $existing
            ? Payment::where('cash_close_id', $existing->id)
            : $this->unclosedPayments($technician, $this->endOfDay($tenant, $date));
        $rows = (clone $payments)->with(['invoice:id,invoice_number,customer_id,job_id', 'invoice.customer:id,name', 'invoice.job:id,crm_call_id'])
            ->orderBy('paid_at')->get();

        $cash = (int) $rows->where('method', 'cash')->sum('amount');
        $cheque = (int) $rows->where('method', 'cheque')->sum('amount');
        $digital = (int) $rows->whereIn('method', self::DIGITAL)->sum('amount');
        $opening = $existing?->opening_balance ?? $this->carryForward($technician);
        $claims = ($existing
            ? ExpenseClaim::where('cash_close_id', $existing->id)->where('status', '!=', 'rejected')
            : $this->unclosedClaims($technician, $date))
            ->with(['category:id,name', 'job:id,crm_call_id'])->orderBy('claim_date')->get();
        $expenses = $existing ? $existing->total_expenses : (int) $claims->sum('amount');

        return [
            'date' => $date,
            'technician' => ['id' => $technician->id, 'name' => $technician->name],
            'close' => $existing,
            'opening_balance' => $opening,
            'cash' => $cash,
            'cheque' => $cheque,
            'digital' => $digital,
            'credit' => $this->creditTotal($technician, $tenant, $date),
            'expenses' => $expenses,
            'expected_in_hand' => $existing ? $existing->expected_in_hand : $opening + $cash + $cheque - $expenses,
            'expense_claims' => $claims->map(fn (ExpenseClaim $c) => [
                'id' => $c->id,
                'claim_date' => $c->claim_date->toDateString(),
                'amount' => $c->amount,
                'status' => $c->status,
                'category' => $c->category?->name,
                'call_id' => $c->job?->crm_call_id,
                'description' => $c->description,
            ])->values(),
            'pending_dates' => $existing ? [] : $this->pendingDatesBefore($technician, $tenant, $date),
            'payments' => $rows->map(fn (Payment $p) => [
                'id' => $p->id,
                'receipt_number' => $p->receipt_number,
                'method' => $p->method,
                'amount' => $p->amount,
                'reference_no' => $p->reference_no,
                'paid_at' => $p->paid_at,
                'invoice_number' => $p->invoice?->invoice_number,
                'call_id' => $p->invoice?->job?->crm_call_id,
                'customer' => $p->invoice?->customer?->name,
            ])->values(),
        ];
    }

    /** FR-15.2 (technician) / FR-15.4 force-close (admin). */
    public function submit(User $technician, string $date, int $amountConfirmed, ?string $remarks, User $by): CashClose
    {
        $tenant = Tenant::findOrFail($technician->tenant_id);
        $today = now($tenant->timezone)->toDateString();
        $force = $by->id !== $technician->id;

        if ($date > $today) {
            throw ValidationException::withMessages(['date' => 'You cannot close a future date.']);
        }

        return DB::transaction(function () use ($technician, $tenant, $date, $amountConfirmed, $remarks, $by, $force) {
            // Serialize closes per technician.
            User::whereKey($technician->id)->lockForUpdate()->first();

            if (CashClose::where('technician_id', $technician->id)->whereDate('close_date', $date)->exists()) {
                throw ValidationException::withMessages(['date' => 'Cash for this date has already been submitted.']);
            }
            if (CashClose::where('technician_id', $technician->id)->whereDate('close_date', '>', $date)->exists()) {
                throw ValidationException::withMessages(['date' => 'A later date is already closed.']);
            }
            if (! $force && $tenant->setting('strict_cash_close', true)) {
                $pending = $this->pendingDatesBefore($technician, $tenant, $date);
                if ($pending) {
                    throw ValidationException::withMessages(['date' => 'Please close '.$pending[0].' first.']);
                }
            }

            $payments = $this->unclosedPayments($technician, $this->endOfDay($tenant, $date))->lockForUpdate()->get();
            $cash = (int) $payments->where('method', 'cash')->sum('amount');
            $cheque = (int) $payments->where('method', 'cheque')->sum('amount');
            $digital = (int) $payments->whereIn('method', self::DIGITAL)->sum('amount');
            $claims = $this->unclosedClaims($technician, $date)->lockForUpdate()->get();
            $expenses = (int) $claims->sum('amount');
            $opening = $this->carryForward($technician);
            $expected = $opening + $cash + $cheque - $expenses;

            if ($amountConfirmed !== $expected && blank($remarks)) {
                throw ValidationException::withMessages(['remarks' => 'Explain the difference between the expected and actual amount.']);
            }

            $close = CashClose::create([
                'branch_id' => $technician->branch_id,
                'technician_id' => $technician->id,
                'close_date' => $date,
                'opening_balance' => $opening,
                'total_cash_collected' => $cash,
                'total_cheque_collected' => $cheque,
                'total_digital_collected' => $digital,
                'total_expenses' => $expenses,
                'expected_in_hand' => $expected,
                'amount_confirmed' => $amountConfirmed,
                'technician_remarks' => $remarks,
                'closing_balance' => $expected,
                'status' => 'submitted',
                'force_closed' => $force,
                'submitted_at' => now(),
                'submitted_by' => $by->id,
            ]);

            // Lock the day's entries (FR-15.2).
            Payment::whereIn('id', $payments->pluck('id'))->update(['cash_close_id' => $close->id]);
            ExpenseClaim::whereIn('id', $claims->pluck('id'))->update(['cash_close_id' => $close->id]);

            // Verified closes with an undeposited balance are now carried into this close.
            CashClose::where('technician_id', $technician->id)->where('id', '!=', $close->id)
                ->where('status', 'verified')->update(['status' => 'closed']);

            return $close;
        });
    }

    /** FR-15.3: accountant verifies the physical amount; discrepancy needs a remark. */
    public function verify(CashClose $close, int $amountVerified, ?string $remarks, User $by): CashClose
    {
        return DB::transaction(function () use ($close, $amountVerified, $remarks, $by) {
            $close = CashClose::whereKey($close->id)->lockForUpdate()->firstOrFail();
            if ($close->status !== 'submitted') {
                throw ValidationException::withMessages(['status' => 'This cash close is already '.$close->status.'.']);
            }
            $discrepancy = $amountVerified - $close->expected_in_hand;
            if ($discrepancy !== 0 && blank($remarks)) {
                throw ValidationException::withMessages(['discrepancy_remarks' => 'A remark is required when there is a shortfall or excess.']);
            }

            $close->fill([
                'amount_verified' => $amountVerified,
                'discrepancy_amount' => $discrepancy,
                'discrepancy_remarks' => $remarks,
                'verified_by' => $by->id,
                'verified_at' => now(),
                'status' => 'verified',
            ]);
            $this->recalculate($close);

            return $close;
        });
    }

    /** Record cash handed to the office / deposited to bank against the latest close. */
    public function deposit(CashClose $close, array $data, User $by): CashDeposit
    {
        return DB::transaction(function () use ($close, $data, $by) {
            $close = CashClose::whereKey($close->id)->lockForUpdate()->firstOrFail();
            $latest = CashClose::where('technician_id', $close->technician_id)->orderByDesc('close_date')->value('id');
            if ($latest !== $close->id) {
                throw ValidationException::withMessages(['cash_close' => 'Record deposits against the technician\'s latest cash close.']);
            }
            if ($close->status === 'closed') {
                throw ValidationException::withMessages(['cash_close' => 'This cash close is fully settled.']);
            }
            if ($data['amount'] > $close->closing_balance) {
                throw ValidationException::withMessages(['amount' => 'Deposit exceeds the cash in hand for this close.']);
            }

            $deposit = CashDeposit::create([
                'cash_close_id' => $close->id,
                'technician_id' => $close->technician_id,
                'amount' => $data['amount'],
                'deposited_to' => $data['deposited_to'],
                'reference_no' => $data['reference_no'] ?? null,
                'deposit_date' => $data['deposit_date'],
                'remarks' => $data['remarks'] ?? null,
                'recorded_by' => $by->id,
            ]);
            $this->recalculate($close);

            return $deposit;
        });
    }

    /** Running cash-in-hand ledger for a technician (FR-15.5). */
    public function ledger(User $technician): array
    {
        $entries = collect();
        $payments = Payment::where('collected_by', $technician->id)->where('status', 'success')
            ->whereIn('method', self::IN_HAND)->with('invoice:id,invoice_number')->get();
        foreach ($payments as $p) {
            $entries->push(['date' => $p->paid_at, 'type' => 'collection', 'description' => ucfirst($p->method).' · '.$p->invoice?->invoice_number.' · '.$p->receipt_number, 'amount' => $p->amount]);
        }
        foreach (CashClose::where('technician_id', $technician->id)->where('discrepancy_amount', '!=', 0)->get() as $c) {
            $entries->push(['date' => $c->verified_at, 'type' => 'discrepancy', 'description' => 'Discrepancy on '.$c->close_date->toDateString().': '.$c->discrepancy_remarks, 'amount' => $c->discrepancy_amount]);
        }
        $claims = ExpenseClaim::where('user_id', $technician->id)->where('paid_from', 'cash_in_hand')
            ->where('status', '!=', 'rejected')->with('category:id,name')->get();
        foreach ($claims as $c) {
            $entries->push(['date' => $c->created_at, 'type' => 'expense', 'description' => 'Expense · '.$c->category?->name.($c->status === 'pending' ? ' (pending approval)' : ''), 'amount' => -$c->amount]);
        }
        foreach (CashDeposit::where('technician_id', $technician->id)->get() as $d) {
            $entries->push(['date' => $d->created_at, 'type' => 'deposit', 'description' => 'Deposited to '.$d->deposited_to.($d->reference_no ? ' · '.$d->reference_no : ''), 'amount' => -$d->amount]);
        }

        $balance = 0;
        $rows = $entries->sortBy('date')->values()->map(function ($e) use (&$balance) {
            $balance += $e['amount'];

            return $e + ['balance' => $balance];
        });

        return ['technician' => ['id' => $technician->id, 'name' => $technician->name], 'cash_in_hand' => $balance, 'entries' => $rows->reverse()->values()];
    }

    public function cashInHand(User $technician): int
    {
        $unclosed = (int) $this->unclosedPayments($technician, now()->addDay())->whereIn('method', self::IN_HAND)->sum('amount');
        $spent = (int) $this->unclosedClaims($technician, null)->sum('amount');

        return $this->carryForward($technician) + $unclosed - $spent;
    }

    private function recalculate(CashClose $close): void
    {
        $close->total_deposited = (int) $close->deposits()->sum('amount');
        $close->closing_balance = $close->expected_in_hand + $close->discrepancy_amount - $close->total_deposited;
        if ($close->status === 'verified' && $close->closing_balance <= 0) {
            $close->status = 'closed';
        }
        $close->save();
    }

    /** Cash still with the technician from previous closes (collections + discrepancies − expenses − deposits). */
    private function carryForward(User $technician): int
    {
        $closes = CashClose::where('technician_id', $technician->id);
        $collected = (int) (clone $closes)->sum(DB::raw('total_cash_collected + total_cheque_collected'));
        $discrepancy = (int) (clone $closes)->sum('discrepancy_amount');
        $expenses = (int) (clone $closes)->sum('total_expenses');
        $deposited = (int) CashDeposit::where('technician_id', $technician->id)->sum('amount');

        return $collected + $discrepancy - $expenses - $deposited;
    }

    /** Expense claims paid from cash in hand, not rejected and not yet in a close (up to $date if given). */
    private function unclosedClaims(User $technician, ?string $date): Builder
    {
        return ExpenseClaim::query()
            ->where('user_id', $technician->id)
            ->where('paid_from', 'cash_in_hand')
            ->where('status', '!=', 'rejected')
            ->whereNull('cash_close_id')
            ->when($date, fn ($q) => $q->whereDate('claim_date', '<=', $date));
    }

    private function unclosedPayments(User $technician, CarbonImmutable|\DateTimeInterface $until): Builder
    {
        return Payment::query()
            ->where('collected_by', $technician->id)
            ->where('status', 'success')
            ->whereNull('cash_close_id')
            ->whereIn('method', [...self::IN_HAND, ...self::DIGITAL])
            ->where('paid_at', '<=', $until);
    }

    /** Local dates before $date that still have unclosed collections (FR-15.4). */
    private function pendingDatesBefore(User $technician, Tenant $tenant, string $date): array
    {
        $start = CarbonImmutable::parse($date, $tenant->timezone)->startOfDay()->utc();

        return $this->unclosedPayments($technician, $start->subSecond())->pluck('paid_at')
            ->map(fn ($d) => $d->timezone($tenant->timezone)->toDateString())
            ->unique()->sort()->values()->all();
    }

    /** Credit sales closed on the tenant-local date (shown for information, never in the close). */
    private function creditTotal(User $technician, Tenant $tenant, string $date): int
    {
        $start = CarbonImmutable::parse($date, $tenant->timezone)->startOfDay()->utc();

        return (int) JobVisit::where('technician_id', $technician->id)->where('payment_method', 'credit')
            ->whereBetween('end_time', [$start, $this->endOfDay($tenant, $date)])->sum('total_charge');
    }

    private function endOfDay(Tenant $tenant, string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, $tenant->timezone)->endOfDay()->utc();
    }
}
