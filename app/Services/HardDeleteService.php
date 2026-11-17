<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\CashClose;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseClaim;
use App\Models\Income;
use App\Models\Invoice;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\JobInventoryUsage;
use App\Models\Payment;
use App\Models\Payslip;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Permanent deletion of test entries: jobs, customers, walk-in bills, receipts, expenses and income.
 *
 * Off by default. An admin switches it on in Settings with their password, and it switches itself off again
 * after HOURS_ON hours, so it is never left on by accident. Unlike the normal delete and cancel actions this
 * removes the record and everything hanging off it, puts used parts back in stock, and ignores closed days:
 * the figures in the accounts change. Every deletion is written to the audit log with what was removed.
 */
class HardDeleteService
{
    public const HOURS_ON = 24;

    public function __construct(private InventoryService $inventory) {}

    /** @return array{enabled: bool, enabled_at: ?string, expires_at: ?string, enabled_by: ?string} */
    public function status(?Tenant $tenant = null): array
    {
        $tenant ??= app(TenantContext::class)->tenant();
        $at = $tenant?->setting('hard_delete.enabled_at');
        $expires = $at ? \Illuminate\Support\Carbon::parse($at)->addHours(self::HOURS_ON) : null;
        $on = (bool) $tenant?->setting('hard_delete.enabled') && $expires && $expires->isFuture();

        return [
            'enabled' => $on,
            'enabled_at' => $on ? $at : null,
            'expires_at' => $on ? $expires->toIso8601String() : null,
            'enabled_by' => $on ? User::inTenant($tenant->id)->whereKey($tenant->setting('hard_delete.enabled_by'))->value('name') : null,
        ];
    }

    public function enabled(?Tenant $tenant = null): bool
    {
        return $this->status($tenant)['enabled'];
    }

    public function set(Tenant $tenant, bool $on, User $by): void
    {
        $settings = $tenant->mergedSettings();
        $settings['hard_delete'] = $on
            ? ['enabled' => true, 'enabled_by' => $by->id, 'enabled_at' => now()->toIso8601String()]
            : ['enabled' => false, 'enabled_by' => null, 'enabled_at' => null];
        $tenant->update(['settings' => $settings]);
        Audit::log($on ? 'hard_delete.enabled' : 'hard_delete.disabled', $tenant);
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages(['hard_delete' => 'Permanent delete is switched off. An admin can switch it on under Settings → Preferences.']);
        }
    }

    /**
     * What deleting a record would remove, as short lines for the confirmation dialog.
     *
     * @return array{title: string, confirm: string, lines: list<string>, warnings: list<string>, blocked: ?string}
     */
    public function preview(string $type, int $id): array
    {
        return match ($type) {
            'job' => $this->jobPreview(ServiceJob::findOrFail($id)),
            'customer' => $this->customerPreview(Customer::withTrashed()->findOrFail($id)),
            'invoice' => $this->invoicePreview(Invoice::findOrFail($id)),
            'payment' => $this->paymentPreview(Payment::findOrFail($id)),
            'expense' => $this->expensePreview(Expense::findOrFail($id)),
            'income' => $this->incomePreview(Income::findOrFail($id)),
        };
    }

    public function delete(string $type, int $id, User $by): string
    {
        $this->assertEnabled();

        return DB::transaction(fn () => match ($type) {
            'job' => $this->deleteJob(ServiceJob::lockForUpdate()->findOrFail($id), $by),
            'customer' => $this->deleteCustomer(Customer::withTrashed()->lockForUpdate()->findOrFail($id), $by),
            'invoice' => $this->deleteInvoice(Invoice::lockForUpdate()->findOrFail($id), $by),
            'payment' => $this->deletePayment(Payment::lockForUpdate()->findOrFail($id), $by),
            'expense' => $this->deleteExpense(Expense::lockForUpdate()->findOrFail($id), $by),
            'income' => $this->deleteIncome(Income::lockForUpdate()->findOrFail($id), $by),
        });
    }

    // ---- Jobs ---------------------------------------------------------------

    private function jobPreview(ServiceJob $job): array
    {
        $job->loadCount(['visits', 'images', 'voiceNotes', 'inventoryUsage', 'followUps'])->load('invoice.payments', 'customer:id,name');
        $payments = $job->invoice?->payments ?? collect();
        $lines = array_values(array_filter([
            "Job {$job->crm_call_id} for ".($job->customer?->name ?? 'the customer').', with its timeline',
            $job->visits_count ? $this->count($job->visits_count, 'visit') : null,
            $job->images_count ? $this->count($job->images_count, 'photo').' and signatures' : null,
            $job->voice_notes_count ? $this->count($job->voice_notes_count, 'voice note') : null,
            $job->inventory_usage_count ? $this->count($job->inventory_usage_count, 'part line').', put back in stock' : null,
            $job->invoice ? "Invoice {$job->invoice->invoice_number}" : null,
            $payments->count() ? $this->count($payments->count(), 'receipt').' ('.$this->money((int) $payments->where('status', 'success')->sum('amount')).')' : null,
        ]));

        return [
            'title' => "Delete job {$job->crm_call_id} permanently?",
            'confirm' => $job->crm_call_id,
            'lines' => $lines,
            'warnings' => array_values(array_filter([
                ...$this->paymentWarnings($payments),
                $job->follow_ups_count ? 'Its follow-up jobs stay, without the link to this job.' : null,
            ])),
            'blocked' => null,
        ];
    }

    private function deleteJob(ServiceJob $job, User $by): string
    {
        $job->load(['visits:id,job_id,technician_id', 'images', 'voiceNotes', 'invoice.payments', 'inventoryUsage']);
        $technicians = $job->visits->pluck('technician_id', 'id');

        foreach ($job->inventoryUsage as $usage) {
            $this->returnUsage($usage, (int) ($technicians[$usage->job_visit_id] ?? $job->assigned_technician_id), $by);
        }
        foreach ($job->invoice?->payments ?? [] as $payment) {
            $this->takeOutOfCashClose($payment);
        }
        $files = [...$job->images->pluck('file_path'), ...$job->voiceNotes->pluck('file_path')];

        Audit::log('hard_deleted', $job, [
            'crm_call_id' => $job->crm_call_id, 'customer_id' => $job->customer_id, 'status' => $job->status->value,
            'invoice' => $job->invoice?->invoice_number, 'invoice_total' => $job->invoice?->total_amount,
            'receipts' => $job->invoice?->payments->map->only(['receipt_number', 'amount', 'method'])->all(),
        ]);
        // Visits, photos, voice notes, timeline, the invoice and its receipts go with the job (foreign keys).
        $job->delete();
        $this->removeFiles($files);

        return "Job {$job->crm_call_id} deleted permanently.";
    }

    // ---- Customers ------------------------------------------------------------

    private function customerPreview(Customer $customer): array
    {
        $jobs = ServiceJob::where('customer_id', $customer->id)->count();
        $bills = Invoice::where('customer_id', $customer->id)->count();

        return [
            'title' => "Delete {$customer->name} permanently?",
            'confirm' => $customer->name,
            'lines' => [
                "Customer {$customer->name}".($customer->phone ? " ({$customer->phone})" : ''),
                $this->count($customer->products()->count(), 'product').' registered to them',
                'Their customer portal login, if they have one',
            ],
            'warnings' => [],
            'blocked' => $jobs || $bills
                ? 'This customer still has '.implode(' and ', array_filter([$jobs ? $this->count($jobs, 'job') : null, $bills ? $this->count($bills, 'bill') : null])).'. Delete those first.'
                : null,
        ];
    }

    private function deleteCustomer(Customer $customer, User $by): string
    {
        if ($blocked = $this->customerPreview($customer)['blocked']) {
            throw ValidationException::withMessages(['hard_delete' => $blocked]);
        }
        Audit::log('hard_deleted', $customer, $customer->only(['name', 'phone', 'crm_id', 'city']));
        User::inTenant($customer->tenant_id)->where('customer_id', $customer->id)->where('role', Role::Customer->value)->get()->each(function (User $u) {
            $u->tokens()->delete();
            $u->delete();
        });
        $customer->forceDelete();

        return "{$customer->name} deleted permanently.";
    }

    // ---- Walk-in bills ----------------------------------------------------------

    private function invoicePreview(Invoice $invoice): array
    {
        $invoice->load('payments', 'customer:id,name');
        $parts = $invoice->items()->where('type', 'part')->count();

        return [
            'title' => "Delete bill {$invoice->invoice_number} permanently?",
            'confirm' => $invoice->invoice_number,
            'lines' => array_values(array_filter([
                "Bill {$invoice->invoice_number} for ".($invoice->customer?->name ?? 'a walk-in customer').' ('.$this->money($invoice->total_amount).')',
                $parts ? $this->count($parts, 'part line').', put back in stock' : null,
                $invoice->payments->count() ? $this->count($invoice->payments->count(), 'receipt').' ('.$this->money((int) $invoice->payments->where('status', 'success')->sum('amount')).')' : null,
            ])),
            'warnings' => [...$this->paymentWarnings($invoice->payments), 'The bill number is not reused: your numbering will have a gap.'],
            'blocked' => $invoice->job_id ? 'This invoice belongs to a job. Delete the job to remove it.' : null,
        ];
    }

    private function deleteInvoice(Invoice $invoice, User $by): string
    {
        if ($invoice->job_id) {
            throw ValidationException::withMessages(['hard_delete' => 'This invoice belongs to a job. Delete the job to remove it.']);
        }
        $invoice->load('payments');
        // Parts sold on the bill go back to the branch they were sold from.
        InventoryTransaction::where('reference_type', 'invoice')->where('reference_id', $invoice->id)->where('type', 'sale')->get()
            ->each(fn (InventoryTransaction $sale) => $this->inventory->adjust($sale->branch_id, InventoryItem::findOrFail($sale->item_id), abs((float) $sale->quantity), "Bill {$invoice->invoice_number} deleted", $by->id));
        foreach ($invoice->payments as $payment) {
            $this->takeOutOfCashClose($payment);
        }
        Audit::log('hard_deleted', $invoice, $invoice->only(['invoice_number', 'customer_id', 'total_amount', 'paid_amount'])
            + ['receipts' => $invoice->payments->map->only(['receipt_number', 'amount', 'method'])->all()]);
        $invoice->delete();

        return "Bill {$invoice->invoice_number} deleted permanently.";
    }

    // ---- Receipts -----------------------------------------------------------------

    private function paymentPreview(Payment $payment): array
    {
        $payment->load('invoice:id,invoice_number');

        return [
            'title' => "Delete receipt {$payment->receipt_number} permanently?",
            'confirm' => (string) $payment->receipt_number,
            'lines' => [
                "Receipt {$payment->receipt_number}: ".$this->money($payment->amount).' by '.str_replace('_', ' ', $payment->method),
                "Invoice {$payment->invoice?->invoice_number} goes back to owing this amount",
            ],
            'warnings' => [...$this->paymentWarnings(collect([$payment])), 'The receipt number is not reused: your numbering will have a gap.'],
            'blocked' => null,
        ];
    }

    private function deletePayment(Payment $payment, User $by): string
    {
        $invoice = Invoice::lockForUpdate()->find($payment->invoice_id);
        $this->takeOutOfCashClose($payment);
        Audit::log('hard_deleted', $payment, $payment->only(['receipt_number', 'invoice_id', 'amount', 'method', 'paid_at', 'collected_by']));
        $payment->delete();
        $invoice?->refreshPaymentTotals();

        return "Receipt {$payment->receipt_number} deleted permanently.";
    }

    // ---- Expenses and income ----------------------------------------------------------

    private function expensePreview(Expense $expense): array
    {
        $claim = ExpenseClaim::where('expense_id', $expense->id)->first();

        return [
            'title' => 'Delete this expense permanently?',
            'confirm' => 'DELETE',
            'lines' => array_values(array_filter([
                'Expense of '.$this->money($expense->amount).' on '.$expense->expense_date->format('j M Y').($expense->paid_to ? " to {$expense->paid_to}" : ''),
                $claim ? 'The staff expense claim it was approved from' : null,
            ])),
            'warnings' => array_values($this->closedDayWarning($expense->expense_date->toDateString())),
            'blocked' => Payslip::where('expense_id', $expense->id)->exists() ? 'This expense is a salary payment. Delete the payroll run under Workforce → Payroll instead.' : null,
        ];
    }

    private function deleteExpense(Expense $expense, User $by): string
    {
        if ($blocked = $this->expensePreview($expense)['blocked']) {
            throw ValidationException::withMessages(['hard_delete' => $blocked]);
        }
        foreach (ExpenseClaim::where('expense_id', $expense->id)->get() as $claim) {
            // A claim paid from collected cash was deducted in a technician's cash close: give that back.
            if ($claim->cash_close_id && $claim->paid_from === 'cash_in_hand' && ($close = CashClose::lockForUpdate()->find($claim->cash_close_id))) {
                $close->total_expenses = max(0, $close->total_expenses - $claim->amount);
                $close->expected_in_hand += $claim->amount;
                $this->rebalance($close);
            }
            $this->removeFiles(array_filter([$claim->receipt_path]));
            $claim->delete();
        }
        Audit::log('hard_deleted', $expense, $expense->only(['expense_date', 'amount', 'payment_method', 'paid_to', 'expense_category_id']));
        $expense->delete();

        return 'Expense deleted permanently.';
    }

    private function incomePreview(Income $income): array
    {
        return [
            'title' => 'Delete this income entry permanently?',
            'confirm' => 'DELETE',
            'lines' => ['Income of '.$this->money($income->amount).' on '.$income->income_date->format('j M Y').($income->received_from ? " from {$income->received_from}" : '')],
            'warnings' => array_values($this->closedDayWarning($income->income_date->toDateString())),
            'blocked' => null,
        ];
    }

    private function deleteIncome(Income $income, User $by): string
    {
        Audit::log('hard_deleted', $income, $income->only(['income_date', 'amount', 'payment_method', 'received_from', 'income_category_id']));
        $income->delete();

        return 'Income entry deleted permanently.';
    }

    // ---- Helpers ------------------------------------------------------------------------

    /** A part used on a visit goes back to the branch, or to the technician's own stock, it came from. */
    private function returnUsage(JobInventoryUsage $usage, int $technicianId, User $by): void
    {
        try {
            $this->inventory->returnUsage($usage, $technicianId, $by->id);
        } catch (\Throwable $e) {
            // The item or the technician no longer exists: the usage line still goes with the job.
            report($e);
        }
    }

    /**
     * A receipt already counted in a technician's submitted cash close: take its amount out of that close,
     * so the cash the technician is shown to hold no longer includes it.
     */
    private function takeOutOfCashClose(Payment $payment): void
    {
        if (! $payment->cash_close_id || $payment->status !== 'success' || ! ($close = CashClose::lockForUpdate()->find($payment->cash_close_id))) {
            return;
        }
        if ($payment->method === 'cash' || $payment->method === 'cheque') {
            $column = $payment->method === 'cash' ? 'total_cash_collected' : 'total_cheque_collected';
            $close->{$column} = max(0, $close->{$column} - $payment->amount);
            $close->expected_in_hand -= $payment->amount;
            $close->amount_confirmed = max(0, $close->amount_confirmed - $payment->amount);
            if ($close->amount_verified !== null) {
                $close->amount_verified = max(0, $close->amount_verified - $payment->amount);
            }
        } else {
            $close->total_digital_collected = max(0, $close->total_digital_collected - $payment->amount);
        }
        $this->rebalance($close);
    }

    private function rebalance(CashClose $close): void
    {
        $close->closing_balance = $close->expected_in_hand + (int) $close->discrepancy_amount - (int) $close->total_deposited;
        $close->save();
    }

    private function paymentWarnings(iterable $payments): array
    {
        $warnings = [];
        foreach ($payments as $payment) {
            if ($payment->cash_close_id) {
                $warnings['close'] = 'A receipt here is part of a technician’s submitted cash close. That close’s totals will be reduced.';
            }
            if ($payment->status === 'success') {
                $warnings += $this->closedDayWarning($payment->paid_at?->timezone(app(TenantContext::class)->tenant()->timezone)->toDateString(), 'day');
            }
            if ($payment->gateway) {
                $warnings['gateway'] = 'An online payment is included. The money stays with the payment gateway: refund it there if needed.';
            }
        }

        return array_values($warnings);
    }

    /** @return array<string, string> */
    private function closedDayWarning(?string $date, string $key = 'day'): array
    {
        return $date && app(OfficeDayCloseService::class)->isLocked($date)
            ? [$key => 'The office day close for this date is already counted. Its saved figures stay as they were, so reopen and close it again if they should change.']
            : [];
    }

    private function removeFiles(iterable $paths): void
    {
        foreach ($paths as $path) {
            if ($path) {
                Storage::disk('private')->delete($path);
            }
        }
    }

    private function count(int $n, string $noun): string
    {
        return $n.' '.$noun.($n === 1 ? '' : 's');
    }

    private function money(int $minor): string
    {
        return \App\Support\Money::format($minor, app(TenantContext::class)->tenant()->currency ?? 'INR');
    }
}
