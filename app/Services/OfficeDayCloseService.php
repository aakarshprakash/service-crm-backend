<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\CashDeposit;
use App\Models\Expense;
use App\Models\ExpenseClaim;
use App\Models\FundTransfer;
use App\Models\Income;
use App\Models\OfficeDayClose;
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The office's end-of-day cash count, kept apart from the technicians' cash closes.
 *
 * The cash book counts cash still with technicians as company cash, so its closing balance is not what the office
 * drawer holds. The drawer is worked out from the money that physically passed through the office since the last
 * count: payments taken at the counter, cash technicians handed over, other cash income and cash withdrawn from the
 * bank, less expenses paid from the drawer and cash deposited in the bank. Expenses a technician paid from the cash
 * they collected never touched the drawer, so they are left out.
 *
 * Each count starts from the previous count (not the previous expected figure), so a shortfall is recorded once and
 * doesn't repeat. The difference is also posted to the cash book, so the books keep agreeing with the drawer.
 *
 * Closing a day locks the office's entries on or before it: expenses, other income, bank transfers, payments recorded
 * at the office, technicians' deposits and payroll. Technicians keep collecting in the field. Only the latest close
 * can be reopened, by an admin.
 */
class OfficeDayCloseService
{
    public function __construct(private BooksService $books, private CashCloseService $cash) {}

    private function tz(): string
    {
        return app(TenantContext::class)->tenant()->timezone;
    }

    public function today(): string
    {
        return CarbonImmutable::now($this->tz())->toDateString();
    }

    public function latest(): ?OfficeDayClose
    {
        return OfficeDayClose::orderByDesc('close_date')->first();
    }

    /** The last day the office has closed (everything on or before it is locked), or null. */
    public function closedThrough(): ?string
    {
        return OfficeDayClose::max('close_date');
    }

    public function isLocked(string $date): bool
    {
        $through = $this->closedThrough();

        return $through !== null && substr($date, 0, 10) <= $through;
    }

    /**
     * Refuse a change dated on a closed day. $field is the request field the error belongs to.
     *
     * @throws ValidationException
     */
    public function assertOpen(?string $date, string $field = 'date'): void
    {
        $date = substr($date ?: $this->today(), 0, 10);
        if ($this->isLocked($date)) {
            throw ValidationException::withMessages([$field => $this->lockedMessage($date)]);
        }
    }

    public function lockedMessage(string $date): string
    {
        $through = CarbonImmutable::parse($this->closedThrough())->format('j M Y');
        $day = CarbonImmutable::parse($date)->format('j M Y');

        return $day === $through
            ? "The office day {$day} is closed. An admin can reopen it to make changes."
            : "Office days up to {$through} are closed, so {$day} can't be changed. An admin can reopen the last close.";
    }

    /** $date if it is still open, otherwise the first open day after the last close (null when that is after today). */
    public function firstOpenDate(string $date): ?string
    {
        if (! $this->isLocked($date)) {
            return $date;
        }
        $next = CarbonImmutable::parse($this->closedThrough())->addDay()->toDateString();

        return $next <= $this->today() ? $next : null;
    }

    /**
     * Money that moved through the office drawer between local dates (inclusive).
     *
     * @return array<string, int>
     */
    public function flows(string $from, string $to): array
    {
        [$start, $end] = $this->books->bounds($from, $to, $this->tz());

        // Cash taken by anyone but a technician: the counter, walk-in bills, office staff. Technicians' collections
        // stay with them until they hand them over.
        $counter = (int) Payment::query()->where('payments.status', 'success')->where('payments.method', 'cash')
            ->whereBetween('payments.paid_at', [$start, $end])
            ->leftJoin('users', 'users.id', '=', 'payments.collected_by')
            ->where(fn ($q) => $q->whereNull('payments.collected_by')->orWhere('users.role', '!=', Role::Technician->value))
            ->sum('payments.amount');

        $income = (int) Income::query()->where('payment_method', 'cash')->whereBetween('income_date', [$from, $to])->sum('amount');

        $handed = (int) CashDeposit::query()->where('deposited_to', 'office')->whereBetween('deposit_date', [$from, $to])->sum('amount');

        // Approved claims a technician paid from collected cash: booked as cash expenses, but never from the drawer.
        $fromCollections = ExpenseClaim::query()->where('paid_from', 'cash_in_hand')->whereNotNull('expense_id')->select('expense_id');
        $expenses = (int) Expense::query()->where('payment_method', 'cash')->whereBetween('expense_date', [$from, $to])
            ->whereNotIn('id', $fromCollections)->sum('amount');

        $transfers = FundTransfer::query()->whereBetween('transfer_date', [$from, $to])->get(['direction', 'amount']);

        return [
            'counter_receipts' => $counter,
            'other_income' => $income,
            'from_technicians' => $handed,
            'from_bank' => (int) $transfers->where('direction', 'bank_to_cash')->sum('amount'),
            'cash_expenses' => $expenses,
            'to_bank' => (int) $transfers->where('direction', 'cash_to_bank')->sum('amount'),
        ];
    }

    /** @param array<string, int> $flows */
    private static function expected(int $opening, array $flows): int
    {
        return $opening + $flows['counter_receipts'] + $flows['other_income'] + $flows['from_technicians'] + $flows['from_bank']
            - $flows['cash_expenses'] - $flows['to_bank'];
    }

    /** What closing $date would look like, or the close already made for it. */
    public function preview(string $date): array
    {
        $existing = OfficeDayClose::with('closer:id,name')->whereDate('close_date', $date)->first();
        $latest = $this->latest();
        $through = $latest?->close_date->toDateString();
        $base = [
            'date' => $date,
            'closed_through' => $through,
            'close' => $existing ? $this->present($existing, $latest) : null,
            'with_technicians' => (int) User::inTenant()->technicians()->where('status', '!=', 'invited')->get()->sum(fn (User $t) => $this->cash->cashInHand($t)),
        ];
        if ($existing || ($through && $date <= $through) || $date > $this->today()) {
            return $base + ['can_close' => false, 'period_from' => null, 'previous' => null, 'needs_opening' => false, 'opening_cash' => null, 'flows' => null, 'expected_cash' => null];
        }

        $from = $latest ? $latest->close_date->addDay()->toDateString() : $date;
        $flows = $this->flows($from, $date);
        $opening = $latest?->counted_cash;

        return $base + [
            'can_close' => true,
            'period_from' => $from,
            'previous' => $latest ? ['date' => $through, 'counted_cash' => $latest->counted_cash] : null,
            'needs_opening' => $latest === null,
            'opening_cash' => $opening,
            'flows' => $flows,
            'expected_cash' => $opening === null ? null : self::expected($opening, $flows),
        ];
    }

    /** @param array{date: string, counted_cash: int, opening_cash?: int|null, remarks?: string|null} $data */
    public function close(array $data, User $by): OfficeDayClose
    {
        $date = $data['date'];
        if ($date > $this->today()) {
            throw ValidationException::withMessages(['date' => 'You can only close today or an earlier day.']);
        }

        return DB::transaction(function () use ($data, $date, $by) {
            // One close at a time per company: lock the latest row (or nothing) so two closes can't race.
            $latest = OfficeDayClose::orderByDesc('close_date')->lockForUpdate()->first();
            if ($latest && $date <= $latest->close_date->toDateString()) {
                throw ValidationException::withMessages(['date' => $date === $latest->close_date->toDateString()
                    ? 'This day is already closed.'
                    : 'Days up to '.$latest->close_date->format('j M Y').' are already closed. Close a later day.']);
            }
            if (! $latest && ! isset($data['opening_cash'])) {
                throw ValidationException::withMessages(['opening_cash' => 'Enter the cash that was in the drawer before this day. Later closes start from your last count.']);
            }

            $opening = $latest ? $latest->counted_cash : (int) $data['opening_cash'];
            $from = $latest ? $latest->close_date->addDay()->toDateString() : $date;
            $flows = $this->flows($from, $date);
            $expected = self::expected($opening, $flows);
            $difference = $data['counted_cash'] - $expected;
            $remarks = trim((string) ($data['remarks'] ?? '')) ?: null;
            if ($difference !== 0 && $remarks === null) {
                throw ValidationException::withMessages(['remarks' => 'Explain why the count is '.($difference < 0 ? 'short' : 'extra').'.']);
            }

            return OfficeDayClose::create($flows + [
                'close_date' => $date,
                'period_from' => $from,
                'opening_cash' => $opening,
                'expected_cash' => $expected,
                'counted_cash' => $data['counted_cash'],
                'difference' => $difference,
                'remarks' => $remarks,
                'closed_by' => $by->id,
            ])->load('closer:id,name');
        });
    }

    /** Reopen the latest close: its day and everything after the previous close can be changed again. */
    public function reopen(OfficeDayClose $close): void
    {
        DB::transaction(function () use ($close) {
            $latest = OfficeDayClose::orderByDesc('close_date')->lockForUpdate()->first();
            if (! $latest || $latest->id !== $close->id) {
                throw ValidationException::withMessages(['close' => 'Only the latest office close can be reopened. Reopen '.$latest?->close_date->format('j M Y').' first.']);
            }
            Audit::log('reopened', $close, $close->only(['close_date', 'expected_cash', 'counted_cash', 'difference', 'remarks', 'closed_by']));
            $close->delete();
        });
    }

    /** API shape of a close; `reopenable` is true for the latest one. */
    public function present(OfficeDayClose $close, ?OfficeDayClose $latest = null): array
    {
        $latest ??= $this->latest();

        return $close->only(['id', 'opening_cash', ...OfficeDayClose::FLOWS, 'expected_cash', 'counted_cash', 'difference', 'remarks']) + [
            'close_date' => $close->close_date->toDateString(),
            'period_from' => $close->period_from->toDateString(),
            'closed_by' => $close->closer?->name,
            'closed_at' => $close->created_at?->toIso8601String(),
            'reopenable' => $latest?->id === $close->id,
        ];
    }
}
