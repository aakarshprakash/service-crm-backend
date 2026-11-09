<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Mini accounts: money in (successful payments on job invoices and walk-in bills) against
 * money out (expenses) for a period, in the company's timezone.
 */
class BooksService
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC bounds of the local days from..to */
    public function bounds(string $from, string $to, string $tz): array
    {
        return [
            CarbonImmutable::parse($from, $tz)->startOfDay()->utc(),
            CarbonImmutable::parse($to, $tz)->endOfDay()->utc(),
        ];
    }

    public function summary(string $from, string $to, string $tz, ?int $branchId = null): array
    {
        [$start, $end] = $this->bounds($from, $to, $tz);

        $payments = Payment::query()->where('payments.status', 'success')->whereBetween('payments.paid_at', [$start, $end])
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->when($branchId, fn ($q) => $q->where('invoices.branch_id', $branchId));
        $expenses = Expense::query()->whereBetween('expense_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('expenses.branch_id', $branchId));
        // Money received without an invoice (manual income).
        $incomes = Income::query()->whereBetween('income_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('incomes.branch_id', $branchId));

        $incomeByMethod = (clone $payments)->selectRaw('payments.method, SUM(payments.amount) as t')->groupBy('payments.method')->pluck('t', 'method')->map(fn ($v) => (int) $v);
        $manualByMethod = (clone $incomes)->selectRaw('payment_method, SUM(amount) as t')->groupBy('payment_method')->pluck('t', 'payment_method')->map(fn ($v) => (int) $v);
        foreach ($manualByMethod as $method => $amount) {
            $incomeByMethod[$method] = ($incomeByMethod[$method] ?? 0) + $amount;
        }
        $incomeByCategory = (clone $incomes)->leftJoin('income_categories as ic', 'ic.id', '=', 'incomes.income_category_id')
            ->selectRaw("COALESCE(ic.name, 'Other income') as category, SUM(incomes.amount) as t")
            ->groupBy('category')->orderByDesc('t')->pluck('t', 'category')->map(fn ($v) => (int) $v);
        $incomeBySource = (clone $payments)->selectRaw('invoices.source, SUM(payments.amount) as t')->groupBy('invoices.source')->pluck('t', 'source')->map(fn ($v) => (int) $v);
        $expenseByMethod = (clone $expenses)->selectRaw('payment_method, SUM(amount) as t')->groupBy('payment_method')->pluck('t', 'payment_method')->map(fn ($v) => (int) $v);
        $expenseByCategory = (clone $expenses)->leftJoin('expense_categories as c', 'c.id', '=', 'expenses.expense_category_id')
            ->selectRaw("COALESCE(c.name, 'Uncategorised') as category, SUM(expenses.amount) as t")
            ->groupBy('category')->orderByDesc('t')->pluck('t', 'category')->map(fn ($v) => (int) $v);

        // Daily series (local dates) for the chart.
        $offset = CarbonImmutable::now($tz)->format('P');
        $dailyIncome = (clone $payments)->selectRaw("DATE(CONVERT_TZ(payments.paid_at, '+00:00', ?)) as d, SUM(payments.amount) as t", [$offset])
            ->groupBy('d')->pluck('t', 'd');
        $dailyExpense = (clone $expenses)->selectRaw('DATE(expense_date) as d, SUM(amount) as t')->groupBy('d')->pluck('t', 'd');
        $dailyManual = (clone $incomes)->selectRaw('DATE(income_date) as d, SUM(amount) as t')->groupBy('d')->pluck('t', 'd');
        $days = collect(CarbonPeriod::create($from, $to))->map(fn ($d) => $d->format('Y-m-d'));
        $series = $days->count() <= 92 ? $days->map(fn ($d) => [
            'date' => $d,
            'income' => (int) ($dailyIncome[$d] ?? 0) + (int) ($dailyManual[$d] ?? 0),
            'expense' => (int) ($dailyExpense[$d] ?? 0),
        ])->values() : collect();

        $income = (int) $incomeByMethod->sum();
        $expense = (int) $expenseByMethod->sum();
        $cashIn = (int) ($incomeByMethod['cash'] ?? 0);
        $cashOut = (int) ($expenseByMethod['cash'] ?? 0);
        $gst = $this->gstCollected($start, $end, $branchId);

        return [
            'from' => $from,
            'to' => $to,
            'income' => $income,                 // money received (GST-inclusive)
            'gst' => $gst,                       // GST inside that money – owed to the government, not income
            'expense' => $expense,
            'net' => $income - $gst - $expense,
            'income_by_method' => $incomeByMethod,
            'income_by_source' => ['job' => (int) ($incomeBySource['job'] ?? 0), 'walk_in' => (int) ($incomeBySource['walk_in'] ?? 0), 'other' => (int) $manualByMethod->sum()],
            'income_by_category' => $incomeByCategory,
            'expense_by_method' => $expenseByMethod,
            'expense_by_category' => $expenseByCategory,
            'cash' => ['in' => $cashIn, 'out' => $cashOut, 'net' => $cashIn - $cashOut],
            'series' => $series,
        ];
    }

    /**
     * GST contained in the payments received between $start and $end (UTC).
     *
     * Each payment carries its invoice's GST in proportion, worked out cumulatively:
     * GST up to and including a payment = round(paid so far × invoice GST / invoice total), and the payment's
     * share is the increase. A fully paid invoice's shares therefore add up to exactly its GST, to the paisa.
     */
    public function gstCollected(CarbonImmutable $start, CarbonImmutable $end, ?int $branchId = null): int
    {
        return array_sum($this->gstByDay($start, $end, 'UTC', $branchId));
    }

    /** The same GST, by local payment date: [Y-m-d => paise]. */
    public function gstByDay(CarbonImmutable $start, CarbonImmutable $end, string $tz, ?int $branchId = null): array
    {
        $invoiceIds = Payment::query()->where('payments.status', 'success')->whereBetween('payments.paid_at', [$start, $end])
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->where('invoices.is_gst', true)
            ->when($branchId, fn ($q) => $q->where('invoices.branch_id', $branchId))
            ->distinct()->pluck('invoices.id');
        if ($invoiceIds->isEmpty()) {
            return [];
        }

        $invoices = Invoice::whereIn('id', $invoiceIds)->get(['id', 'total_amount', 'cgst_amount', 'sgst_amount', 'igst_amount'])->keyBy('id');
        $payments = Payment::whereIn('invoice_id', $invoiceIds)->where('status', 'success')
            ->orderBy('paid_at')->orderBy('id')->get(['id', 'invoice_id', 'amount', 'paid_at']);

        $byDay = [];
        foreach ($payments->groupBy('invoice_id') as $invoiceId => $rows) {
            $inv = $invoices[$invoiceId];
            $tax = $inv->cgst_amount + $inv->sgst_amount + $inv->igst_amount;
            if ($inv->total_amount <= 0 || $tax === 0) {
                continue;
            }
            $paid = 0;
            $taxSoFar = 0;
            foreach ($rows as $p) {
                $paid = min($inv->total_amount, $paid + (int) $p->amount);
                $upTo = intdiv(2 * $paid * $tax + $inv->total_amount, 2 * $inv->total_amount);
                if ($p->paid_at >= $start && $p->paid_at <= $end) {
                    $day = CarbonImmutable::parse($p->paid_at)->timezone($tz)->toDateString();
                    $byDay[$day] = ($byDay[$day] ?? 0) + $upTo - $taxSoFar;
                }
                $taxSoFar = $upTo;
            }
        }

        return $byDay;
    }

    /**
     * Every rupee in and out, oldest first.
     *
     * @return list<array{at: string, kind: string, ref: ?string, party: ?string, details: ?string, method: string, in: int, out: int}>
     */
    public function dayBook(string $from, string $to, string $tz, ?int $branchId = null): array
    {
        [$start, $end] = $this->bounds($from, $to, $tz);

        // No row cap: the day book's totals must include every entry in the period.
        $in = Payment::query()->where('payments.status', 'success')->whereBetween('payments.paid_at', [$start, $end])
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->when($branchId, fn ($q) => $q->where('invoices.branch_id', $branchId))
            ->orderBy('payments.paid_at')->orderBy('payments.id')
            ->get(['payments.paid_at', 'payments.receipt_number', 'payments.method', 'payments.amount', 'invoices.invoice_number', 'invoices.source', 'customers.name as customer'])
            ->map(fn ($p) => [
                'at' => CarbonImmutable::parse($p->paid_at)->timezone($tz)->format('Y-m-d H:i'),
                'kind' => $p->source === 'walk_in' ? 'Walk-in sale' : 'Service payment',
                'ref' => $p->receipt_number,
                'party' => $p->customer,
                'details' => "Invoice {$p->invoice_number}",
                'method' => $p->method,
                'in' => (int) $p->amount,
                'out' => 0,
            ]);

        $out = Expense::query()->whereBetween('expense_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('category:id,name')->orderBy('expense_date')->orderBy('id')->get()
            ->map(fn (Expense $e) => [
                'at' => $e->expense_date->format('Y-m-d').' 00:00',
                'kind' => 'Expense',
                'ref' => $e->reference_no,
                'party' => $e->paid_to,
                'details' => trim(($e->category?->name ?? 'Uncategorised').($e->description ? ' — '.$e->description : '')),
                'method' => $e->payment_method,
                'in' => 0,
                'out' => (int) $e->amount,
            ]);

        $manual = Income::query()->whereBetween('income_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('category:id,name')->orderBy('income_date')->orderBy('id')->get()
            ->map(fn (Income $i) => [
                'at' => $i->income_date->format('Y-m-d').' 00:00',
                'kind' => 'Other income',
                'ref' => $i->reference_no,
                'party' => $i->received_from,
                'details' => trim(($i->category?->name ?? 'Other income').($i->description ? ' — '.$i->description : '')),
                'method' => $i->payment_method,
                'in' => (int) $i->amount,
                'out' => 0,
            ]);

        return $in->concat($manual)->concat($out)->sortBy('at')->values()->all();
    }
}
