<?php

namespace App\Services;

use App\Models\Expense;
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

        $incomeByMethod = (clone $payments)->selectRaw('payments.method, SUM(payments.amount) as t')->groupBy('payments.method')->pluck('t', 'method')->map(fn ($v) => (int) $v);
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
        $days = collect(CarbonPeriod::create($from, $to))->map(fn ($d) => $d->format('Y-m-d'));
        $series = $days->count() <= 92 ? $days->map(fn ($d) => [
            'date' => $d,
            'income' => (int) ($dailyIncome[$d] ?? 0),
            'expense' => (int) ($dailyExpense[$d] ?? 0),
        ])->values() : collect();

        $income = (int) $incomeByMethod->sum();
        $expense = (int) $expenseByMethod->sum();
        $cashIn = (int) ($incomeByMethod['cash'] ?? 0);
        $cashOut = (int) ($expenseByMethod['cash'] ?? 0);

        return [
            'from' => $from,
            'to' => $to,
            'income' => $income,
            'expense' => $expense,
            'net' => $income - $expense,
            'income_by_method' => $incomeByMethod,
            'income_by_source' => ['job' => (int) ($incomeBySource['job'] ?? 0), 'walk_in' => (int) ($incomeBySource['walk_in'] ?? 0)],
            'expense_by_method' => $expenseByMethod,
            'expense_by_category' => $expenseByCategory,
            'cash' => ['in' => $cashIn, 'out' => $cashOut, 'net' => $cashIn - $cashOut],
            'series' => $series,
        ];
    }

    /**
     * Every rupee in and out, oldest first.
     *
     * @return list<array{at: string, kind: string, ref: ?string, party: ?string, details: ?string, method: string, in: int, out: int}>
     */
    public function dayBook(string $from, string $to, string $tz, ?int $branchId = null, int $limit = 5000): array
    {
        [$start, $end] = $this->bounds($from, $to, $tz);

        $in = Payment::query()->where('payments.status', 'success')->whereBetween('payments.paid_at', [$start, $end])
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->when($branchId, fn ($q) => $q->where('invoices.branch_id', $branchId))
            ->orderBy('payments.paid_at')->limit($limit)
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
            ->with('category:id,name')->orderBy('expense_date')->orderBy('id')->limit($limit)->get()
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

        return $in->concat($out)->sortBy('at')->values()->all();
    }
}
