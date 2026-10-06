<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\FundTransfer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Accounts module (v2.1), on a simple cash basis:
 *  - Cash book: cash receipts, cash expenses and contra transfers.
 *  - Bank book: UPI / cheque / bank transfer / online receipts, non-cash expenses and transfers.
 *  - Receivables with aging, a period overview and a profit & loss statement.
 * Opening balances live in the tenant settings under `books.*`.
 */
class LedgerService
{
    public const BOOKS = ['cash', 'bank'];

    public function __construct(private BooksService $books) {}

    /** Payment methods that land in each book (credit is not money received). */
    public static function methodsFor(string $book): array
    {
        return $book === 'cash' ? ['cash'] : ['upi', 'cheque', 'bank_transfer', 'online'];
    }

    /** @return array{date: ?string, cash: int, bank: int} */
    public function opening(Tenant $tenant): array
    {
        return [
            'date' => $tenant->setting('books.opening_date'),
            'cash' => (int) $tenant->setting('books.opening_cash', 0),
            'bank' => (int) $tenant->setting('books.opening_bank', 0),
        ];
    }

    /**
     * Cash book or bank book for local dates from..to with opening and closing balances.
     *
     * @return array{book: string, from: string, to: string, opening: int, entries: list<array>, total_in: int, total_out: int, closing: int}
     */
    public function ledger(Tenant $tenant, string $book, string $from, string $to): array
    {
        $tz = $tenant->timezone;
        $opening = $this->opening($tenant);
        $since = $opening['date'];

        // Balance brought forward = opening balance + every movement after the opening date and before `from`.
        $before = CarbonImmutable::parse($from)->subDay()->toDateString();
        $carried = $opening[$book];
        if (! $since || $since <= $before) {
            $carried += $this->net($this->movements($tenant, $book, $since ?? '2000-01-01', $before));
        }

        $start = $since && $since > $from ? $since : $from;
        $entries = $start <= $to ? $this->movements($tenant, $book, $start, $to) : collect();
        $balance = $carried;
        $rows = $entries->map(function (array $e) use (&$balance) {
            $balance += $e['in'] - $e['out'];

            return $e + ['balance' => $balance];
        })->values()->all();

        $in = (int) $entries->sum('in');
        $out = (int) $entries->sum('out');

        return [
            'book' => $book, 'from' => $from, 'to' => $to, 'opening' => $carried, 'entries' => $rows,
            'total_in' => $in, 'total_out' => $out, 'closing' => $carried + $in - $out,
        ];
    }

    /** Current balance of a book (as of today, company timezone). */
    public function balance(Tenant $tenant, string $book): int
    {
        $today = CarbonImmutable::now($tenant->timezone)->toDateString();
        $since = $this->opening($tenant)['date'] ?? '2000-01-01';

        return $this->opening($tenant)[$book] + ($since <= $today ? $this->net($this->movements($tenant, $book, $since, $today)) : 0);
    }

    private function net(Collection $movements): int
    {
        return (int) ($movements->sum('in') - $movements->sum('out'));
    }

    /** All movements in a book between local dates (inclusive), oldest first. */
    private function movements(Tenant $tenant, string $book, string $from, string $to): Collection
    {
        $tz = $tenant->timezone;
        [$start, $end] = $this->books->bounds($from, $to, $tz);
        $methods = self::methodsFor($book);

        $receipts = Payment::query()->where('payments.status', 'success')->whereIn('payments.method', $methods)
            ->whereBetween('payments.paid_at', [$start, $end])
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->get(['payments.id', 'payments.paid_at', 'payments.receipt_number', 'payments.reference_no', 'payments.method', 'payments.amount',
                'invoices.id as invoice_id', 'invoices.invoice_number', 'customers.name as customer'])
            ->map(fn ($p) => [
                'at' => CarbonImmutable::parse($p->paid_at)->timezone($tz)->format('Y-m-d H:i'),
                'type' => 'receipt',
                'ref' => $p->receipt_number ?? $p->reference_no,
                'particulars' => trim(($p->customer ?? 'Customer').' · '.$p->invoice_number),
                'method' => $p->method,
                'link' => ['invoice_id' => $p->invoice_id],
                'in' => (int) $p->amount,
                'out' => 0,
            ]);

        $expenses = Expense::query()->whereIn('payment_method', $methods)->whereBetween('expense_date', [$from, $to])
            ->with('category:id,name')->get()
            ->map(fn (Expense $e) => [
                'at' => $e->expense_date->format('Y-m-d').' 23:59',
                'type' => 'expense',
                'ref' => $e->reference_no,
                'particulars' => trim(($e->category?->name ?? 'Expense').($e->paid_to ? ' · '.$e->paid_to : '')),
                'method' => $e->payment_method,
                'link' => ['expense_id' => $e->id],
                'in' => 0,
                'out' => (int) $e->amount,
            ]);

        $transfers = FundTransfer::query()->whereBetween('transfer_date', [$from, $to])->get()
            ->map(function (FundTransfer $t) use ($book) {
                $intoThisBook = ($t->direction === 'cash_to_bank') === ($book === 'bank');

                return [
                    'at' => $t->transfer_date->format('Y-m-d').' 12:00',
                    'type' => 'transfer',
                    'ref' => $t->reference_no,
                    'particulars' => $t->direction === 'cash_to_bank' ? 'Cash deposited in bank' : 'Cash withdrawn from bank',
                    'method' => 'contra',
                    'link' => ['transfer_id' => $t->id],
                    'in' => $intoThisBook ? (int) $t->amount : 0,
                    'out' => $intoThisBook ? 0 : (int) $t->amount,
                ];
            });

        return $receipts->concat($expenses)->concat($transfers)->sortBy('at')->values();
    }

    /**
     * Money owed by customers, one row per customer, with aging buckets by invoice age.
     *
     * @return array{rows: list<array>, totals: array<string, int>, customers: int}
     */
    public function receivables(?string $search = null, ?int $branchId = null): array
    {
        $like = $search ? '%'.addcslashes($search, '%_\\').'%' : null;
        $invoices = Invoice::query()->where('balance_amount', '>', 0)
            ->with('customer:id,name,phone')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($like, fn ($q) => $q->whereHas('customer', fn ($c) => $c->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like))))
            ->get(['id', 'customer_id', 'invoice_number', 'generated_at', 'balance_amount', 'is_credit']);

        $bucket = fn (int $days) => match (true) {
            $days <= 30 => 'd0_30', $days <= 60 => 'd31_60', $days <= 90 => 'd61_90', default => 'd90_plus'
        };
        $totals = ['total' => 0, 'd0_30' => 0, 'd31_60' => 0, 'd61_90' => 0, 'd90_plus' => 0];

        $rows = $invoices->groupBy('customer_id')->map(function (Collection $group) use ($bucket, &$totals) {
            $row = ['total' => 0, 'd0_30' => 0, 'd31_60' => 0, 'd61_90' => 0, 'd90_plus' => 0];
            $oldest = 0;
            foreach ($group as $inv) {
                $days = (int) $inv->generated_at->diffInDays(now());
                $oldest = max($oldest, $days);
                $row[$bucket($days)] += $inv->balance_amount;
                $row['total'] += $inv->balance_amount;
            }
            foreach ($row as $k => $v) {
                $totals[$k] += $v;
            }
            $customer = $group->first()->customer;

            return $row + [
                'customer' => ['id' => $customer?->id, 'name' => $customer?->name ?? 'Deleted customer', 'phone' => $customer?->phone],
                'invoices' => $group->count(),
                'credit' => $group->contains('is_credit', true),
                'oldest_days' => $oldest,
            ];
        })->sortByDesc('total')->values()->all();

        return ['rows' => $rows, 'totals' => $totals, 'customers' => count($rows)];
    }

    /**
     * Profit & loss statement for a period, compared with the period of the same length just before it.
     */
    public function profitLoss(Tenant $tenant, string $from, string $to, ?int $branchId = null): array
    {
        $tz = $tenant->timezone;
        $f = CarbonImmutable::parse($from);
        $t = CarbonImmutable::parse($to);
        $days = $f->diffInDays($t) + 1;
        $prevTo = $f->subDay();
        $prevFrom = $prevTo->subDays($days - 1);

        $current = $this->books->summary($from, $to, $tz, $branchId);
        $previous = $this->books->summary($prevFrom->toDateString(), $prevTo->toDateString(), $tz, $branchId);

        $categories = collect(array_keys($current['expense_by_category']->all()))
            ->merge(array_keys($previous['expense_by_category']->all()))->unique()->values();

        $line = fn (string $label, int $cur, int $prev) => ['label' => $label, 'current' => $cur, 'previous' => $prev];

        return [
            'period' => ['from' => $from, 'to' => $to],
            'previous_period' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
            'income' => [
                $line('Service income (jobs)', $current['income_by_source']['job'], $previous['income_by_source']['job']),
                $line('Walk-in sales', $current['income_by_source']['walk_in'], $previous['income_by_source']['walk_in']),
            ],
            'expenses' => $categories->map(fn ($c) => $line($c, (int) ($current['expense_by_category'][$c] ?? 0), (int) ($previous['expense_by_category'][$c] ?? 0)))
                ->sortByDesc('current')->values()->all(),
            'total_income' => ['current' => $current['income'], 'previous' => $previous['income']],
            'total_expense' => ['current' => $current['expense'], 'previous' => $previous['expense']],
            'net' => ['current' => $current['net'], 'previous' => $previous['net']],
            'margin' => [
                'current' => $current['income'] ? round($current['net'] / $current['income'] * 100, 1) : null,
                'previous' => $previous['income'] ? round($previous['net'] / $previous['income'] * 100, 1) : null,
            ],
        ];
    }

    /** Monthly income vs expenses for the last N months (oldest first), for the overview chart. */
    public function monthlyTrend(Tenant $tenant, int $months = 6): array
    {
        $tz = $tenant->timezone;
        $now = CarbonImmutable::now($tz);

        return collect(range($months - 1, 0))->map(function (int $back) use ($now, $tz) {
            $m = $now->startOfMonth()->subMonths($back);
            $end = $back === 0 ? $now : $m->endOfMonth();
            $s = $this->books->summary($m->toDateString(), $end->toDateString(), $tz);

            return ['month' => $m->format('Y-m'), 'income' => $s['income'], 'expense' => $s['expense'], 'net' => $s['net']];
        })->all();
    }
}
