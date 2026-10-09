<?php

namespace App\Services;

use App\Models\CashClose;
use App\Models\Expense;
use App\Models\ExpenseClaim;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ImageOptimizer;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Field expense claims: staff submit what they spent (with a receipt photo), the office
 * approves or rejects. Approval books a real Expense. Claims paid from the
 * technician's cash in hand are deducted at the daily cash close (CashCloseService).
 */
class ExpenseClaimService
{
    public function __construct(private NotificationService $notifications) {}

    public function submit(User $user, array $data): ExpenseClaim
    {
        $claim = ExpenseClaim::create([
            'user_id' => $user->id,
            'job_id' => $data['job_id'] ?? null,
            'expense_category_id' => $data['expense_category_id'],
            'claim_date' => $data['claim_date'],
            'amount' => $data['amount'],
            'paid_from' => $data['paid_from'],
            'description' => $data['description'] ?? null,
            'status' => 'pending',
        ]);

        DB::afterCommit(function () use ($claim, $user) {
            $claim->load('category:id,name');
            $this->notifications->notifyRoles(['admin', 'accountant'], 'expense_claim', 'Expense claim',
                "{$user->name} · {$this->money($claim)} · {$claim->category?->name}", ['expense_claim_id' => $claim->id]);
        });

        return $claim;
    }

    public function attachReceipt(ExpenseClaim $claim, UploadedFile $file): ExpenseClaim
    {
        $this->ensurePending($claim);
        $dir = "tenants/{$claim->tenant_id}/expense-claims";
        $optimized = ImageOptimizer::shrink($file->getRealPath());
        if ($optimized) {
            $path = $dir.'/'.Str::uuid().'.'.$optimized[1];
            Storage::disk('private')->put($path, $optimized[0]);
        } else {
            $path = $file->storeAs($dir, Str::uuid().'.'.($file->guessExtension() ?: 'jpg'), 'private');
        }
        if ($claim->receipt_path) {
            Storage::disk('private')->delete($claim->receipt_path);
        }
        $claim->update(['receipt_path' => $path]);

        return $claim;
    }

    /** $method is how the office reimbursed an own-money claim (cash in hand claims are cash). */
    public function approve(ExpenseClaim $claim, User $by, ?string $method, ?string $note): ExpenseClaim
    {
        $claim = DB::transaction(function () use ($claim, $by, $method, $note) {
            $claim = ExpenseClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            $this->ensurePending($claim);
            if ($claim->paid_from === 'own_money' && ! in_array($method, Expense::METHODS, true)) {
                throw ValidationException::withMessages(['payment_method' => 'Select how the amount was reimbursed.']);
            }
            $claim->loadMissing(['user:id,name,branch_id', 'job:id,crm_call_id']);

            $expense = Expense::create([
                'expense_date' => $claim->claim_date,
                'expense_category_id' => $claim->expense_category_id,
                'amount' => $claim->amount,
                'payment_method' => $claim->paid_from === 'cash_in_hand' ? 'cash' : $method,
                'paid_to' => $claim->user?->name,
                'description' => mb_substr(trim('Claim #'.$claim->id.($claim->job ? ' · '.$claim->job->crm_call_id : '').' · '.($claim->description ?? '')), 0, 500),
                'branch_id' => $claim->user?->branch_id,
                'user_id' => $claim->user_id,
                'created_by' => $by->id,
            ]);
            $claim->update([
                'status' => 'approved',
                'expense_id' => $expense->id,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            return $claim;
        });
        $this->notifyDecision($claim);

        return $claim;
    }

    public function reject(ExpenseClaim $claim, User $by, string $note): ExpenseClaim
    {
        $claim = DB::transaction(function () use ($claim, $by, $note) {
            $claim = ExpenseClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            $this->ensurePending($claim);

            // Already deducted in a cash close: put the amount back into what the technician owes.
            if ($claim->cash_close_id) {
                $close = CashClose::whereKey($claim->cash_close_id)->lockForUpdate()->firstOrFail();
                if ($close->status !== 'submitted') {
                    throw ValidationException::withMessages([
                        'claim' => 'This expense was settled in the verified cash close of '.$close->close_date->format('d M Y').'. Record the difference on the technician\'s cash instead.',
                    ]);
                }
                $close->total_expenses -= $claim->amount;
                $close->expected_in_hand += $claim->amount;
                $close->closing_balance = $close->expected_in_hand + $close->discrepancy_amount - $close->total_deposited;
                $close->save();
            }
            $claim->update(['status' => 'rejected', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note]);

            return $claim;
        });
        $this->notifyDecision($claim);

        return $claim;
    }

    /** The claimant may withdraw a pending claim that isn't part of a cash close yet. */
    public function withdraw(ExpenseClaim $claim): void
    {
        $this->ensurePending($claim);
        if ($claim->cash_close_id) {
            throw ValidationException::withMessages(['claim' => 'This expense is part of a submitted cash close and can no longer be removed.']);
        }
        if ($claim->receipt_path) {
            Storage::disk('private')->delete($claim->receipt_path);
        }
        $claim->delete();
    }

    private function ensurePending(ExpenseClaim $claim): void
    {
        if ($claim->status !== 'pending') {
            throw ValidationException::withMessages(['claim' => 'This expense claim has already been '.$claim->status.'.']);
        }
    }

    private function notifyDecision(ExpenseClaim $claim): void
    {
        $claim->loadMissing('category:id,name');
        // A full user record (the claim may hold a partially loaded one).
        $user = User::find($claim->user_id);
        if (! $user) {
            return;
        }
        $approved = $claim->status === 'approved';
        $this->notifications->notifyUser($user, 'expense_claim_decided', 'Expense claim '.$claim->status,
            $this->money($claim).' · '.$claim->category?->name.($approved ? '' : ($claim->decision_note ? ' · '.$claim->decision_note : '')),
            ['expense_claim_id' => $claim->id]);
    }

    private function money(ExpenseClaim $claim): string
    {
        return Money::format($claim->amount, Tenant::find($claim->tenant_id)?->currency ?? 'INR');
    }
}
