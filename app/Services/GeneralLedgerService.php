<?php

namespace App\Services;

use App\Models\CashDeposit;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FundTransfer;
use App\Models\Income;
use App\Models\IncomeCategory;
use App\Models\Invoice;
use App\Models\OfficeDayClose;
use App\Models\Payment;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The ledger: every money record posted to accounts by double entry, the way an accounting package keeps
 * its books. Nothing new is typed in; the entries come from what is already recorded:
 *
 *  - Bill (sales):       Dr Customers            Cr Sales (taxable value), Cr GST payable (the tax)
 *  - Bill payment:       Dr Cash or Bank         Cr Customers
 *  - Other income:       Dr Cash or Bank         Cr that income head
 *  - Expense:            Dr that expense head    Cr Cash or Bank
 *  - Deposit / withdraw: Dr Bank, Cr Cash (or the other way round)
 *  - Office cash count:  the shortfall or excess between Cash and "Cash short & excess"
 *  - Opening balances:   Dr Cash, Bank and what customers owed then, Cr Capital
 *
 * Cash and Bank agree with the cash book and bank book (LedgerService) entry for entry. Sales are counted on the
 * bill date; the Profit & loss page counts them when the money comes in.
 * Amounts are paise, signed: positive is a debit, negative a credit.
 */
class GeneralLedgerService
{
    public const GROUPS = [
        'assets' => 'Cash, bank and customers',
        'liabilities' => 'Taxes',
        'equity' => 'Capital',
        'income' => 'Income',
        'expenses' => 'Expenses',
    ];

    private const SOURCES = ['opening', 'invoices', 'receipts', 'incomes', 'expenses', 'transfers', 'deposits', 'counts'];

    private const MONEY_METHODS = ['cash', 'upi', 'cheque', 'bank_transfer', 'online'];

    public function __construct(private BooksService $books, private LedgerService $cashBook) {}

    /**
     * Every account with its opening balance at `from`, the debits and credits in the period and the closing
     * balance: the trial balance, whose debit and credit columns always agree.
     */
    public function trialBalance(Tenant $tenant, string $from, string $to): array
    {
        $before = $this->totals($tenant, '2000-01-01', $this->dayBefore($from));
        $during = $this->totals($tenant, $from, $to);
        $chart = $this->chart(array_keys($before['net'] + $during['net']));

        $accounts = collect($chart)->map(function (array $a) use ($before, $during) {
            $opening = (int) ($before['net'][$a['code']] ?? 0);
            $debit = (int) ($during['dr'][$a['code']] ?? 0);
            $credit = (int) ($during['cr'][$a['code']] ?? 0);

            return $a + ['opening' => $opening, 'debit' => $debit, 'credit' => $credit, 'closing' => $opening + $debit - $credit];
        })->values();

        $side = fn (string $key, int $sign) => (int) $accounts->sum(fn ($a) => max(0, $sign * $a[$key]));

        return [
            'from' => $from,
            'to' => $to,
            'opening_date' => $this->cashBook->opening($tenant)['date'],
            'accounts' => $accounts->all(),
            'totals' => [
                'opening_dr' => $side('opening', 1), 'opening_cr' => $side('opening', -1),
                'debit' => (int) $accounts->sum('debit'), 'credit' => (int) $accounts->sum('credit'),
                'closing_dr' => $side('closing', 1), 'closing_cr' => $side('closing', -1),
            ],
            'profit' => [
                'income' => (int) -$accounts->where('group', 'income')->sum(fn ($a) => $a['debit'] - $a['credit']),
                'expenses' => (int) $accounts->where('group', 'expenses')->sum(fn ($a) => $a['debit'] - $a['credit']),
            ],
        ];
    }

    /** Each customer's account: what they owed at `from`, billed, paid, and owe at `to`. */
    public function customers(Tenant $tenant, string $from, string $to, ?string $search = null): array
    {
        $before = $this->totals($tenant, '2000-01-01', $this->dayBefore($from));
        $during = $this->totals($tenant, $from, $to);
        $ids = collect(array_keys($before['parties'] + $during['party_dr'] + $during['party_cr']));
        $names = $ids->isEmpty() ? collect() : Customer::withTrashed()->whereIn('id', $ids)
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')->orWhere('phone', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->get(['id', 'name', 'phone'])->keyBy('id');

        $rows = $names->map(function (Customer $c) use ($before, $during) {
            $opening = (int) ($before['parties'][$c->id] ?? 0);
            $debit = (int) ($during['party_dr'][$c->id] ?? 0);
            $credit = (int) ($during['party_cr'][$c->id] ?? 0);

            return ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'opening' => $opening, 'debit' => $debit, 'credit' => $credit, 'closing' => $opening + $debit - $credit];
        })->filter(fn ($r) => $r['opening'] || $r['debit'] || $r['credit'] || $r['closing'])
            ->sortByDesc('closing')->values();

        return ['from' => $from, 'to' => $to, 'rows' => $rows->all(), 'total' => (int) $rows->sum('closing')];
    }

    /**
     * One account's ledger for local dates from..to: the balance brought forward, each entry with the account
     * on the other side, and the running balance. `customer:{id}` is one customer's account.
     */
    public function account(Tenant $tenant, string $code, string $from, string $to): array
    {
        [$account, $party] = $this->resolve($code);
        $before = $this->totals($tenant, '2000-01-01', $this->dayBefore($from));
        $opening = $party ? (int) ($before['parties'][$party] ?? 0) : (int) ($before['net'][$account] ?? 0);

        $vouchers = $this->vouchers($tenant, $from, $to, $this->sourcesFor($account), $party);
        $names = collect($this->chart($vouchers->flatMap(fn ($v) => array_column($v['lines'], 'account'))->unique()->all()))->pluck('name', 'code');

        $balance = $opening;
        $entries = [];
        foreach ($vouchers as $v) {
            $mine = array_filter($v['lines'], fn ($l) => $l['account'] === $account && (! $party || ($l['party'] ?? null) === $party));
            if (! $mine) {
                continue;
            }
            $amount = array_sum(array_column($mine, 'amount'));
            // Particulars name the account(s) on the other side of the entry, as in a printed ledger.
            $opposite = array_filter($v['lines'], fn ($l) => $l['account'] !== $account && ($l['amount'] > 0) !== ($amount > 0));
            $others = array_values(array_unique(array_map(fn ($l) => $names[$l['account']] ?? $l['account'],
                $opposite ?: array_filter($v['lines'], fn ($l) => $l['account'] !== $account))));
            $balance += $amount;
            $entries[] = [
                'date' => $v['date'],
                'type' => $v['type'],
                'number' => $v['number'],
                'particulars' => $others ? implode(', ', $others) : ($names[$account] ?? $account),
                'narration' => $v['narration'],
                'link' => $v['link'],
                'debit' => max(0, $amount),
                'credit' => max(0, -$amount),
                'balance' => $balance,
            ];
        }

        $debit = (int) array_sum(array_column($entries, 'debit'));
        $credit = (int) array_sum(array_column($entries, 'credit'));
        $meta = $party
            ? ['code' => $code, 'name' => Customer::withTrashed()->find($party)?->name ?? 'Customer', 'group' => 'assets', 'kind' => 'customer', 'note' => 'What this customer was billed and what they paid.']
            : (collect($this->chart([$account]))->firstWhere('code', $account) ?? ['code' => $account, 'name' => $account, 'group' => 'assets', 'kind' => 'other']);

        return [
            'account' => $meta,
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
            'entries' => $entries,
            'total_debit' => $debit,
            'total_credit' => $credit,
            'closing' => $opening + $debit - $credit,
        ];
    }

    /** The accounts that exist, in order, with names: the fixed ones and the income and expense heads used. */
    public function chart(array $used = []): array
    {
        $fixed = [
            ['code' => 'cash', 'name' => 'Cash', 'group' => 'assets', 'kind' => 'cash', 'note' => 'All the company’s cash: in the office and with technicians.'],
            ['code' => 'bank', 'name' => 'Bank / UPI', 'group' => 'assets', 'kind' => 'bank', 'note' => 'UPI, cheques, bank transfers and online payments.'],
            ['code' => 'debtors', 'name' => 'Customers (receivable)', 'group' => 'assets', 'kind' => 'debtors', 'note' => 'Bills raised, less what customers paid.'],
            ['code' => 'gst', 'name' => 'GST payable', 'group' => 'liabilities', 'kind' => 'tax', 'note' => 'GST on your bills, owed to the government.'],
            ['code' => 'capital', 'name' => 'Capital (opening balances)', 'group' => 'equity', 'kind' => 'capital', 'note' => 'What the business started with in Servon.'],
            ['code' => 'sales_job', 'name' => 'Service income (job bills)', 'group' => 'income', 'kind' => 'sales', 'note' => 'Job bills, before GST, on the bill date.'],
            ['code' => 'sales_walkin', 'name' => 'Counter sales (walk-in bills)', 'group' => 'income', 'kind' => 'sales', 'note' => 'Walk-in bills, before GST, on the bill date.'],
        ];
        $incomeHeads = IncomeCategory::query()->orderBy('name')->get(['id', 'name', 'is_active']);
        $expenseHeads = ExpenseCategory::query()->orderBy('name')->get(['id', 'name', 'is_active']);
        $used = array_flip($used);
        $keep = fn (string $code, $head) => isset($used[$code]) || ($head->is_active ?? true);

        $income = $incomeHeads->filter(fn ($c) => $keep("income:{$c->id}", $c))
            ->map(fn ($c) => ['code' => "income:{$c->id}", 'name' => $c->name, 'group' => 'income', 'kind' => 'income', 'note' => 'Other income (no bill).'])->values()->all();
        if (isset($used['income:0'])) {
            $income[] = ['code' => 'income:0', 'name' => 'Other income (no category)', 'group' => 'income', 'kind' => 'income', 'note' => 'Other income (no bill).'];
        }
        $expenses = $expenseHeads->filter(fn ($c) => $keep("expense:{$c->id}", $c))
            ->map(fn ($c) => ['code' => "expense:{$c->id}", 'name' => $c->name, 'group' => 'expenses', 'kind' => 'expense', 'note' => null])->values()->all();
        if (isset($used['expense:0'])) {
            $expenses[] = ['code' => 'expense:0', 'name' => 'Uncategorised expenses', 'group' => 'expenses', 'kind' => 'expense', 'note' => null];
        }
        $diff = isset($used['cash_diff'])
            ? [['code' => 'cash_diff', 'name' => 'Cash short & excess', 'group' => 'expenses', 'kind' => 'expense', 'note' => 'Office cash counts that did not match the book.']]
            : [];

        return [...$fixed, ...$income, ...$expenses, ...$diff];
    }

    /** "customer:12" is the Customers account for one customer. */
    private function resolve(string $code): array
    {
        if (preg_match('/^customer:(\d+)$/', $code, $m)) {
            return ['debtors', (int) $m[1]];
        }

        return [$code, null];
    }

    private function sourcesFor(string $account): array
    {
        return match (true) {
            $account === 'cash' => ['opening', 'receipts', 'incomes', 'expenses', 'transfers', 'deposits', 'counts'],
            $account === 'bank' => ['opening', 'receipts', 'incomes', 'expenses', 'transfers', 'deposits'],
            $account === 'debtors' => ['opening', 'invoices', 'receipts'],
            $account === 'capital' => ['opening'],
            in_array($account, ['gst', 'sales_job', 'sales_walkin'], true) => ['invoices'],
            str_starts_with($account, 'income:') => ['incomes'],
            str_starts_with($account, 'expense:') => ['expenses'],
            $account === 'cash_diff' => ['counts'],
            default => [],
        };
    }

    private static function book(string $method): string
    {
        return $method === 'cash' ? 'cash' : 'bank';
    }

    private function dayBefore(string $date): string
    {
        return CarbonImmutable::parse($date)->subDay()->toDateString();
    }

    /**
     * The entries between local dates (inclusive), each with its lines, oldest first. Entries before the books'
     * opening date are left out: the opening balances already hold them.
     */
    private function vouchers(Tenant $tenant, string $from, string $to, array $sources, ?int $party = null): Collection
    {
        $tz = $tenant->timezone;
        $opening = $this->cashBook->opening($tenant);
        $since = $opening['date'];
        $start = $since && $since > $from ? $since : $from;
        if ($start > $to) {
            return collect();
        }
        [$utcStart, $utcEnd] = $this->books->bounds($start, $to, $tz);
        $want = array_flip($sources);
        $out = collect();
        $local = fn ($at) => CarbonImmutable::parse($at)->timezone($tz);

        if (isset($want['opening']) && $since && $since >= $from && $since <= $to) {
            $owed = $this->owedBefore($tenant, $since);
            $lines = [];
            if (! $party) {
                $lines[] = ['account' => 'cash', 'amount' => $opening['cash']];
                $lines[] = ['account' => 'bank', 'amount' => $opening['bank']];
            }
            foreach ($owed as $customerId => $amount) {
                if (! $party || $party === $customerId) {
                    $lines[] = ['account' => 'debtors', 'party' => $customerId, 'amount' => $amount];
                }
            }
            // Capital takes the other side of everything brought in.
            $lines[] = ['account' => 'capital', 'amount' => -($opening['cash'] + $opening['bank'] + array_sum($owed))];
            $out->push(['date' => $since, 'at' => $since.' 00:00', 'type' => 'opening', 'number' => null,
                'narration' => 'Opening balances', 'link' => ['opening' => true], 'lines' => $lines]);
        }

        if (isset($want['invoices'])) {
            Invoice::query()->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
                ->whereRaw('COALESCE(invoices.generated_at, invoices.created_at) BETWEEN ? AND ?', [$utcStart->toDateTimeString(), $utcEnd->toDateTimeString()])
                ->where('invoices.total_amount', '!=', 0)
                ->when($party, fn ($q) => $q->where('invoices.customer_id', $party))
                ->orderByRaw('COALESCE(invoices.generated_at, invoices.created_at)')->orderBy('invoices.id')
                ->get(['invoices.id', 'invoices.invoice_number', 'invoices.source', 'invoices.customer_id', 'invoices.total_amount', 'invoices.is_gst',
                    'invoices.cgst_amount', 'invoices.sgst_amount', 'invoices.igst_amount', 'invoices.generated_at', 'invoices.created_at', 'customers.name as customer'])
                ->each(function ($i) use ($out, $local) {
                    $at = $local($i->generated_at ?? $i->created_at);
                    $tax = (int) $i->cgst_amount + (int) $i->sgst_amount + (int) $i->igst_amount;
                    $lines = [['account' => 'debtors', 'party' => (int) $i->customer_id, 'amount' => (int) $i->total_amount],
                        ['account' => $i->source === 'walk_in' ? 'sales_walkin' : 'sales_job', 'amount' => -((int) $i->total_amount - $tax)]];
                    if ($tax) {
                        $lines[] = ['account' => 'gst', 'amount' => -$tax];
                    }
                    $out->push(['date' => $at->toDateString(), 'at' => $at->format('Y-m-d H:i'), 'type' => 'sales', 'number' => $i->invoice_number,
                        'narration' => ($i->customer ?? 'Customer').($i->source === 'walk_in' ? ', walk-in bill' : ', job bill'),
                        'link' => ['invoice_id' => $i->id], 'lines' => $lines]);
                });
        }

        if (isset($want['receipts'])) {
            Payment::query()->where('payments.status', 'success')->whereIn('payments.method', self::MONEY_METHODS)
                ->whereBetween('payments.paid_at', [$utcStart, $utcEnd])
                ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
                ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
                ->when($party, fn ($q) => $q->where('invoices.customer_id', $party))
                ->orderBy('payments.paid_at')->orderBy('payments.id')
                ->get(['payments.id', 'payments.paid_at', 'payments.receipt_number', 'payments.reference_no', 'payments.method', 'payments.amount',
                    'invoices.id as invoice_id', 'invoices.invoice_number', 'invoices.customer_id', 'customers.name as customer'])
                ->each(function ($p) use ($out, $local) {
                    $at = $local($p->paid_at);
                    $out->push(['date' => $at->toDateString(), 'at' => $at->format('Y-m-d H:i'), 'type' => 'receipt', 'number' => $p->receipt_number ?? $p->reference_no,
                        'narration' => trim(($p->customer ?? 'Customer').', bill '.$p->invoice_number.($p->method !== 'cash' ? ' ('.str_replace('_', ' ', $p->method).')' : '')),
                        'link' => ['invoice_id' => $p->invoice_id],
                        'lines' => [['account' => self::book($p->method), 'amount' => (int) $p->amount], ['account' => 'debtors', 'party' => (int) $p->customer_id, 'amount' => -(int) $p->amount]]]);
                });
        }

        if ($party) {
            return $out->sortBy('at')->values();
        }

        if (isset($want['incomes'])) {
            Income::query()->whereBetween('income_date', [$start, $to])->with('category:id,name')->orderBy('income_date')->orderBy('id')->get()
                ->each(fn (Income $i) => $out->push(['date' => $i->income_date->format('Y-m-d'), 'at' => $i->income_date->format('Y-m-d').' 12:00', 'type' => 'income',
                    'number' => $i->reference_no, 'narration' => trim(($i->received_from ?: 'Received').($i->description ? ', '.$i->description : '')),
                    'link' => ['income_id' => $i->id],
                    'lines' => [['account' => self::book($i->payment_method), 'amount' => (int) $i->amount], ['account' => 'income:'.((int) $i->income_category_id), 'amount' => -(int) $i->amount]]]));
        }

        if (isset($want['expenses'])) {
            Expense::query()->whereBetween('expense_date', [$start, $to])->orderBy('expense_date')->orderBy('id')->get()
                ->each(fn (Expense $e) => $out->push(['date' => $e->expense_date->format('Y-m-d'), 'at' => $e->expense_date->format('Y-m-d').' 23:59', 'type' => 'payment',
                    'number' => $e->reference_no, 'narration' => trim(($e->paid_to ?: 'Paid').($e->description ? ', '.$e->description : '')),
                    'link' => ['expense_id' => $e->id],
                    'lines' => [['account' => 'expense:'.((int) $e->expense_category_id), 'amount' => (int) $e->amount], ['account' => self::book($e->payment_method), 'amount' => -(int) $e->amount]]]));
        }

        if (isset($want['transfers'])) {
            FundTransfer::query()->whereBetween('transfer_date', [$start, $to])->orderBy('transfer_date')->orderBy('id')->get()
                ->each(function (FundTransfer $t) use ($out) {
                    $toBank = $t->direction === 'cash_to_bank';
                    $out->push(['date' => $t->transfer_date->format('Y-m-d'), 'at' => $t->transfer_date->format('Y-m-d').' 12:00', 'type' => 'contra', 'number' => $t->reference_no,
                        'narration' => ($toBank ? 'Cash deposited in bank' : 'Cash withdrawn from bank').($t->notes ? ', '.$t->notes : ''),
                        'link' => ['transfer_id' => $t->id],
                        'lines' => [['account' => $toBank ? 'bank' : 'cash', 'amount' => (int) $t->amount], ['account' => $toBank ? 'cash' : 'bank', 'amount' => -(int) $t->amount]]]);
                });
        }

        if (isset($want['deposits'])) {
            CashDeposit::query()->where('deposited_to', 'bank')->whereBetween('deposit_date', [$start, $to])->with('technician:id,name')->orderBy('deposit_date')->orderBy('id')->get()
                ->each(fn (CashDeposit $d) => $out->push(['date' => $d->deposit_date->format('Y-m-d'), 'at' => $d->deposit_date->format('Y-m-d').' 18:00', 'type' => 'contra',
                    'number' => $d->reference_no, 'narration' => 'Cash deposited in bank by '.($d->technician?->name ?? 'technician'),
                    'link' => ['cash_close_id' => $d->cash_close_id],
                    'lines' => [['account' => 'bank', 'amount' => (int) $d->amount], ['account' => 'cash', 'amount' => -(int) $d->amount]]]));
        }

        if (isset($want['counts'])) {
            OfficeDayClose::query()->where('difference', '!=', 0)->whereBetween('close_date', [$start, $to])->orderBy('close_date')->get()
                ->each(fn (OfficeDayClose $c) => $out->push(['date' => $c->close_date->format('Y-m-d'), 'at' => $c->close_date->format('Y-m-d').' 23:59', 'type' => 'journal', 'number' => null,
                    'narration' => 'Office cash count '.($c->difference < 0 ? 'short' : 'extra').($c->remarks ? ': '.$c->remarks : ''),
                    'link' => ['office_close_date' => $c->close_date->format('Y-m-d')],
                    'lines' => [['account' => 'cash', 'amount' => (int) $c->difference], ['account' => 'cash_diff', 'amount' => -(int) $c->difference]]]));
        }

        return $out->sortBy('at')->values();
    }

    /** What each customer still owed when the books were opened in Servon (bills before that date, less payments). */
    private function owedBefore(Tenant $tenant, string $since): array
    {
        [, $end] = $this->books->bounds('2000-01-01', $this->dayBefore($since), $tenant->timezone);
        $billed = Invoice::query()->whereRaw('COALESCE(generated_at, created_at) <= ?', [$end->toDateTimeString()])
            ->selectRaw('customer_id, SUM(total_amount) as t')->groupBy('customer_id')->pluck('t', 'customer_id');
        $paid = Payment::query()->where('payments.status', 'success')->whereIn('payments.method', self::MONEY_METHODS)->where('payments.paid_at', '<=', $end)
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->selectRaw('invoices.customer_id, SUM(payments.amount) as t')->groupBy('invoices.customer_id')->pluck('t', 'customer_id');

        return collect($billed->keys())->merge($paid->keys())->unique()
            ->mapWithKeys(fn ($id) => [(int) $id => (int) ($billed[$id] ?? 0) - (int) ($paid[$id] ?? 0)])
            ->filter()->all();
    }

    /**
     * Each account's debits and credits between local dates (inclusive), added up by the database: the same
     * figures as summing vouchers(), without reading every entry. Only entries from the opening date count;
     * the opening entry is included when the opening date falls in the range.
     *
     * @return array{net: array<string, int>, dr: array<string, int>, cr: array<string, int>, parties: array<int, int>, party_dr: array<int, int>, party_cr: array<int, int>}
     */
    public function totals(Tenant $tenant, string $from, string $to): array
    {
        $t = ['net' => [], 'dr' => [], 'cr' => [], 'parties' => [], 'party_dr' => [], 'party_cr' => []];
        $post = function (string $account, int $amount, ?int $party = null) use (&$t) {
            if ($amount === 0) {
                return;
            }
            $t['net'][$account] = ($t['net'][$account] ?? 0) + $amount;
            $t[$amount > 0 ? 'dr' : 'cr'][$account] = ($t[$amount > 0 ? 'dr' : 'cr'][$account] ?? 0) + abs($amount);
            if ($party !== null) {
                $t['parties'][$party] = ($t['parties'][$party] ?? 0) + $amount;
                $t[$amount > 0 ? 'party_dr' : 'party_cr'][$party] = ($t[$amount > 0 ? 'party_dr' : 'party_cr'][$party] ?? 0) + abs($amount);
            }
        };

        $opening = $this->cashBook->opening($tenant);
        $since = $opening['date'];
        $start = $since && $since > $from ? $since : $from;
        if ($since && $since >= $from && $since <= $to) {
            $owed = $this->owedBefore($tenant, $since);
            $post('cash', $opening['cash']);
            $post('bank', $opening['bank']);
            foreach ($owed as $customerId => $amount) {
                $post('debtors', $amount, $customerId);
            }
            $post('capital', -($opening['cash'] + $opening['bank'] + array_sum($owed)));
        }
        if ($start > $to) {
            return $t;
        }
        [$utcStart, $utcEnd] = $this->books->bounds($start, $to, $tenant->timezone);

        Invoice::query()->whereRaw('COALESCE(generated_at, created_at) BETWEEN ? AND ?', [$utcStart->toDateTimeString(), $utcEnd->toDateTimeString()])
            ->selectRaw('source, customer_id, SUM(total_amount) as t, SUM(cgst_amount + sgst_amount + igst_amount) as g')
            ->groupBy('source', 'customer_id')->get()
            ->each(function ($r) use ($post) {
                $post('debtors', (int) $r->t, (int) $r->customer_id);
                $post($r->source === 'walk_in' ? 'sales_walkin' : 'sales_job', -((int) $r->t - (int) $r->g));
                $post('gst', -(int) $r->g);
            });

        Payment::query()->where('payments.status', 'success')->whereIn('payments.method', self::MONEY_METHODS)
            ->whereBetween('payments.paid_at', [$utcStart, $utcEnd])
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->selectRaw('payments.method as m, invoices.customer_id as c, SUM(payments.amount) as t')
            ->groupBy('payments.method', 'invoices.customer_id')->get()
            ->each(function ($r) use ($post) {
                $post(self::book($r->m), (int) $r->t);
                $post('debtors', -(int) $r->t, (int) $r->c);
            });

        Income::query()->whereBetween('income_date', [$start, $to])
            ->selectRaw('payment_method as m, income_category_id as c, SUM(amount) as t')->groupBy('payment_method', 'income_category_id')->get()
            ->each(function ($r) use ($post) {
                $post(self::book($r->m), (int) $r->t);
                $post('income:'.((int) $r->c), -(int) $r->t);
            });

        Expense::query()->whereBetween('expense_date', [$start, $to])
            ->selectRaw('payment_method as m, expense_category_id as c, SUM(amount) as t')->groupBy('payment_method', 'expense_category_id')->get()
            ->each(function ($r) use ($post) {
                $post('expense:'.((int) $r->c), (int) $r->t);
                $post(self::book($r->m), -(int) $r->t);
            });

        FundTransfer::query()->whereBetween('transfer_date', [$start, $to])
            ->selectRaw('direction, SUM(amount) as t')->groupBy('direction')->get()
            ->each(function ($r) use ($post) {
                $toBank = $r->direction === 'cash_to_bank';
                $post($toBank ? 'bank' : 'cash', (int) $r->t);
                $post($toBank ? 'cash' : 'bank', -(int) $r->t);
            });

        $deposited = (int) CashDeposit::query()->where('deposited_to', 'bank')->whereBetween('deposit_date', [$start, $to])->sum('amount');
        $post('bank', $deposited);
        $post('cash', -$deposited);

        // Shortfalls and excesses are posted separately, so both show in the debit and credit columns.
        foreach (OfficeDayClose::query()->where('difference', '!=', 0)->whereBetween('close_date', [$start, $to])->pluck('difference') as $d) {
            $post('cash', (int) $d);
            $post('cash_diff', -(int) $d);
        }

        return $t;
    }
}
