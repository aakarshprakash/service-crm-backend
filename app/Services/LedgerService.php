<?php

namespace App\Services;

use App\Models\CashDeposit;
use App\Models\Expense;
use App\Models\Income;
use App\Models\FundTransfer;
use App\Models\Invoice;
use App\Models\OfficeDayClose;
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

        $manual = Income::query()->whereIn('payment_method', $methods)->whereBetween('income_date', [$from, $to])
            ->with('category:id,name')->get()
            ->map(fn (Income $i) => [
                'at' => $i->income_date->format('Y-m-d').' 12:00',
                'type' => 'income',
                'ref' => $i->reference_no,
                'particulars' => trim(($i->category?->name ?? 'Other income').($i->received_from ? ' · '.$i->received_from : '')),
                'method' => $i->payment_method,
                'link' => ['income_id' => $i->id],
                'in' => (int) $i->amount,
                'out' => 0,
            ]);

        // A technician paying collected cash straight into the bank: it leaves the cash book and enters the bank book.
        // Cash handed over at the office stays in the cash book, so it moves nothing.
        $deposits = $this->bankDeposits($from, $to)->map(fn (CashDeposit $d) => [
            'at' => $d->deposit_date->format('Y-m-d').' 18:00',
            'type' => 'transfer',
            'ref' => $d->reference_no,
            'particulars' => 'Cash deposited in bank by '.($d->technician?->name ?? 'technician'),
            'method' => 'contra',
            'link' => ['cash_close_id' => $d->cash_close_id],
            'in' => $book === 'bank' ? (int) $d->amount : 0,
            'out' => $book === 'cash' ? (int) $d->amount : 0,
        ]);

        // A short or extra office cash count is posted to the cash book, so the book keeps agreeing with the drawer.
        $counts = $book !== 'cash' ? collect() : $this->countDifferences($from, $to)->map(fn (OfficeDayClose $c) => [
            'at' => $c->close_date->format('Y-m-d').' 23:59',
            'type' => 'adjustment',
            'ref' => null,
            'particulars' => self::countParticulars($c),
            'method' => 'cash',
            'link' => ['office_close_date' => $c->close_date->format('Y-m-d')],
            'in' => max(0, $c->difference),
            'out' => max(0, -$c->difference),
        ]);

        return $receipts->concat($manual)->concat($expenses)->concat($transfers)->concat($deposits)->concat($counts)->sortBy('at')->values();
    }

    /** Office day closes whose count didn't match, between local dates. */
    private function countDifferences(string $from, string $to): Collection
    {
        return OfficeDayClose::query()->where('difference', '!=', 0)->whereBetween('close_date', [$from, $to])->orderBy('close_date')->get();
    }

    private static function countParticulars(OfficeDayClose $c): string
    {
        return 'Office cash count '.($c->difference < 0 ? 'short' : 'extra').($c->remarks ? ': '.$c->remarks : '');
    }

    /** Technicians' cash-close deposits made straight into the bank, between local dates. */
    private function bankDeposits(string $from, string $to): Collection
    {
        return CashDeposit::query()->where('deposited_to', 'bank')->whereBetween('deposit_date', [$from, $to])
            ->with('technician:id,name')->orderBy('deposit_date')->orderBy('id')->get();
    }

    /**
     * Daily cash transaction register: the cash book and bank book side by side, a day at a time, the way a
     * handwritten register is kept. Money in is grouped by who brought it ("From" a technician, the office counter
     * or online), each line a customer with their phone; money out by who it went to ("To" a person or supplier).
     * Every amount sits in a Cash or a Bank / UPI column, and each day carries its opening and closing balances.
     *
     * @return array{from: string, to: string, opening: array{cash: int, bank: int}, days: list<array>, totals: array<string, int>, closing: array{cash: int, bank: int}}
     */
    public function register(Tenant $tenant, string $from, string $to): array
    {
        $tz = $tenant->timezone;
        $since = $this->opening($tenant)['date'];
        $balance = [
            'cash' => $this->ledger($tenant, 'cash', $from, $from)['opening'],
            'bank' => $this->ledger($tenant, 'bank', $from, $from)['opening'],
        ];
        // Entries before the opening date are already inside the opening balances.
        $start = $since && $since > $from ? $since : $from;
        $book = fn (string $method) => $method === 'cash' ? 'cash' : 'bank';

        $receipts = collect();
        $expenses = collect();
        $transfers = collect();
        $deposits = collect();
        $counts = collect();
        if ($start <= $to) {
            [$utcStart, $utcEnd] = $this->books->bounds($start, $to, $tz);
            // Invoice payments and manual income become the same kind of line: who paid, and who brought it in.
            $paid = Payment::query()->where('payments.status', 'success')
                ->whereIn('payments.method', [...self::methodsFor('cash'), ...self::methodsFor('bank')])
                ->whereBetween('payments.paid_at', [$utcStart, $utcEnd])
                ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
                ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
                ->leftJoin('service_jobs', 'service_jobs.id', '=', 'invoices.job_id')
                ->leftJoin('users', 'users.id', '=', 'payments.collected_by')
                ->orderBy('payments.paid_at')->orderBy('payments.id')
                ->get(['payments.id', 'payments.paid_at', 'payments.method', 'payments.amount', 'payments.receipt_number', 'payments.reference_no', 'payments.collected_by',
                    'invoices.id as invoice_id', 'invoices.invoice_number', 'invoices.source', 'customers.name as customer', 'customers.phone',
                    'service_jobs.crm_call_id', 'users.name as collector'])
                ->map(function ($p) use ($tz, $book) {
                    $at = CarbonImmutable::parse($p->paid_at)->timezone($tz);

                    return [
                        'date' => $at->toDateString(),
                        'group' => $p->collected_by ? 'u'.$p->collected_by : ($p->method === 'online' ? 'online' : 'office'),
                        'collector' => $p->collector,
                        'line' => [
                            'at' => $at->format('H:i'),
                            'customer' => $p->customer ?? 'Customer',
                            'phone' => $p->phone,
                            'invoice_id' => $p->invoice_id,
                            'invoice_number' => $p->invoice_number,
                            'income_id' => null,
                            'category' => null,
                            'job' => $p->crm_call_id,
                            'ref' => $p->receipt_number ?? $p->reference_no,
                            'method' => $p->method,
                            'book' => $book($p->method),
                            'amount' => (int) $p->amount,
                        ],
                    ];
                });

            $manual = Income::query()->whereBetween('income_date', [$start, $to])
                ->with(['category:id,name', 'user:id,name'])->orderBy('income_date')->orderBy('id')->get()
                ->map(fn (Income $i) => [
                    'date' => $i->income_date->format('Y-m-d'),
                    'group' => $i->user_id ? 'u'.$i->user_id : 'office',
                    'collector' => $i->user?->name,
                    'line' => [
                        'at' => null,
                        'customer' => $i->received_from ?: ($i->category?->name ?? 'Other income'),
                        'phone' => $i->phone,
                        'invoice_id' => null,
                        'invoice_number' => null,
                        'income_id' => $i->id,
                        'category' => $i->category?->name ?? 'Other income',
                        'job' => null,
                        'ref' => $i->reference_no,
                        'method' => $i->payment_method,
                        'book' => $book($i->payment_method),
                        'amount' => (int) $i->amount,
                    ],
                ]);

            $receipts = $paid->concat($manual)->groupBy('date');

            $expenses = Expense::query()->whereBetween('expense_date', [$start, $to])
                ->with(['category:id,name', 'user:id,name'])->orderBy('expense_date')->orderBy('id')->get()
                ->groupBy(fn (Expense $e) => $e->expense_date->format('Y-m-d'));

            $transfers = FundTransfer::query()->whereBetween('transfer_date', [$start, $to])->orderBy('id')->get()
                ->groupBy(fn (FundTransfer $t) => $t->transfer_date->format('Y-m-d'));

            $deposits = $this->bankDeposits($start, $to)->groupBy(fn (CashDeposit $d) => $d->deposit_date->format('Y-m-d'));
            $counts = $this->countDifferences($start, $to)->keyBy(fn (OfficeDayClose $c) => $c->close_date->format('Y-m-d'));
        }

        // The office's own end-of-day count for each day, and how far the office has closed (those days are locked).
        $dayCloses = app(OfficeDayCloseService::class);
        $latestClose = $dayCloses->latest();
        $closedThrough = $latestClose?->close_date->format('Y-m-d');
        $closes = OfficeDayClose::with('closer:id,name')->whereBetween('close_date', [$from, $to])->get()
            ->keyBy(fn (OfficeDayClose $c) => $c->close_date->format('Y-m-d'))
            ->map(fn (OfficeDayClose $c) => $dayCloses->present($c, $latestClose));

        $sum = fn ($rows, string $b) => (int) collect($rows)->where('book', $b)->sum('amount');
        $opening = $balance;
        $grand = ['cash_in' => 0, 'bank_in' => 0, 'cash_out' => 0, 'bank_out' => 0];
        $days = [];

        foreach (\Carbon\CarbonPeriod::create($from, $to) as $day) {
            $d = $day->format('Y-m-d');
            $dayOpening = $balance;

            $in = collect($receipts[$d] ?? [])->groupBy('group')
                ->map(function ($rows, $key) use ($sum) {
                    $lines = $rows->pluck('line')->values();

                    return [
                        'party' => $key === 'online' ? 'Paid online' : ($key === 'office' ? 'Office counter' : ($rows->first()['collector'] ?? 'Staff')),
                        'kind' => $key === 'online' || $key === 'office' ? $key : 'staff',
                        'lines' => $lines->all(),
                        'cash' => $sum($lines, 'cash'),
                        'bank' => $sum($lines, 'bank'),
                    ];
                })->sortBy(fn ($g) => [$g['kind'] === 'staff' ? 0 : 1, $g['party']])->values();

            $out = collect($expenses[$d] ?? [])->groupBy(fn (Expense $e) => mb_strtolower(trim($e->paid_to ?: ($e->user?->name ?? ''))) ?: '~')
                ->map(function ($rows) use ($book, $sum) {
                    $first = $rows->first();
                    $lines = $rows->map(fn (Expense $e) => [
                        'expense_id' => $e->id,
                        'category' => $e->category?->name ?? 'Expense',
                        'description' => $e->description,
                        'ref' => $e->reference_no,
                        'method' => $e->payment_method,
                        'book' => $book($e->payment_method),
                        'amount' => (int) $e->amount,
                    ])->values();

                    return [
                        'party' => trim($first->paid_to ?: ($first->user?->name ?? '')) ?: 'Not named',
                        'lines' => $lines->all(),
                        'cash' => $sum($lines, 'cash'),
                        'bank' => $sum($lines, 'bank'),
                    ];
                })->sortBy('party')->values();

            // Contra: money moved between the two columns, in on one side and out on the other.
            $contra = collect($transfers[$d] ?? [])->map(fn (FundTransfer $t) => [
                'transfer_id' => $t->id,
                'particulars' => $t->direction === 'cash_to_bank' ? 'Cash deposited in bank' : 'Cash withdrawn from bank',
                'ref' => $t->reference_no,
                'cash_in' => $t->direction === 'bank_to_cash' ? (int) $t->amount : 0,
                'bank_in' => $t->direction === 'cash_to_bank' ? (int) $t->amount : 0,
                'cash_out' => $t->direction === 'cash_to_bank' ? (int) $t->amount : 0,
                'bank_out' => $t->direction === 'bank_to_cash' ? (int) $t->amount : 0,
            ])->concat(collect($deposits[$d] ?? [])->map(fn (CashDeposit $dep) => [
                'transfer_id' => null,
                'particulars' => 'Cash deposited in bank by '.($dep->technician?->name ?? 'technician'),
                'ref' => $dep->reference_no,
                'cash_in' => 0,
                'bank_in' => (int) $dep->amount,
                'cash_out' => (int) $dep->amount,
                'bank_out' => 0,
            ]))->values();

            // The office cash count's shortfall or excess: cash only, nothing moves in the bank.
            $count = $counts[$d] ?? null;
            $adjustments = $count ? [[
                'particulars' => self::countParticulars($count),
                'cash_in' => max(0, $count->difference),
                'cash_out' => max(0, -$count->difference),
            ]] : [];

            $totals = [
                'cash_in' => (int) $in->sum('cash') + (int) $contra->sum('cash_in') + (int) collect($adjustments)->sum('cash_in'),
                'bank_in' => (int) $in->sum('bank') + (int) $contra->sum('bank_in'),
                'cash_out' => (int) $out->sum('cash') + (int) $contra->sum('cash_out') + (int) collect($adjustments)->sum('cash_out'),
                'bank_out' => (int) $out->sum('bank') + (int) $contra->sum('bank_out'),
            ];
            $balance = [
                'cash' => $balance['cash'] + $totals['cash_in'] - $totals['cash_out'],
                'bank' => $balance['bank'] + $totals['bank_in'] - $totals['bank_out'],
            ];
            foreach ($totals as $k => $v) {
                $grand[$k] += $v;
            }

            $days[] = [
                'date' => $d,
                'opening' => $dayOpening,
                'receipts' => $in->all(),
                'payments' => $out->all(),
                'transfers' => $contra->all(),
                'adjustments' => $adjustments,
                'totals' => $totals,
                'closing' => $balance,
                'empty' => $in->isEmpty() && $out->isEmpty() && $contra->isEmpty() && ! $adjustments,
                'office_close' => $closes[$d] ?? null,
                'locked' => $closedThrough !== null && $d <= $closedThrough,
            ];
        }

        return ['from' => $from, 'to' => $to, 'opening' => $opening, 'days' => $days, 'totals' => $grand, 'closing' => $balance, 'closed_through' => $closedThrough];
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
                $line('Other income (no invoice)', $current['income_by_source']['other'], $previous['income_by_source']['other']),
            ],
            'expenses' => $categories->map(fn ($c) => $line($c, (int) ($current['expense_by_category'][$c] ?? 0), (int) ($previous['expense_by_category'][$c] ?? 0)))
                ->sortByDesc('current')->values()->all(),
            'total_income' => ['current' => $current['income'], 'previous' => $previous['income']],
            // GST inside the receipts belongs to the government: shown separately and left out of profit.
            'gst' => ['current' => $current['gst'], 'previous' => $previous['gst']],
            'net_income' => ['current' => $current['income'] - $current['gst'], 'previous' => $previous['income'] - $previous['gst']],
            'total_expense' => ['current' => $current['expense'], 'previous' => $previous['expense']],
            'net' => ['current' => $current['net'], 'previous' => $previous['net']],
            'margin' => [
                'current' => $current['income'] - $current['gst'] ? round($current['net'] / ($current['income'] - $current['gst']) * 100, 1) : null,
                'previous' => $previous['income'] - $previous['gst'] ? round($previous['net'] / ($previous['income'] - $previous['gst']) * 100, 1) : null,
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
