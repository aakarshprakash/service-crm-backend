<?php

namespace App\Services\Reports;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\CashClose;
use App\Models\Expense;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\JobInventoryUsage;
use App\Models\JobVisit;
use App\Models\Payment;
use App\Models\Review;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BooksService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Report Center (§5.13). Every report returns the same shape – title, columns
 * (with a type used for formatting), rows and an optional summary – so one export
 * pipeline (Excel + PDF) serves all of them (FR-13.8).
 */
class ReportService
{
    /** key => [title, ability required, description] */
    public const REPORTS = [
        'jobs' => ['Job Report', 'reports.view', 'Jobs by status, technician, branch, complaint type and priority.'],
        'technician-performance' => ['Technician Performance', 'reports.view', 'Jobs completed, visit duration, ratings and cash handling per technician.'],
        'revenue' => ['Revenue Report', 'reports.financial', 'Collected vs pending, split by payment method.'],
        'cash-collection' => ['Daily Cash Collection', 'reports.financial', 'Offline collections per technician per day by method.'],
        'cash-close' => ['Daily Cash Close', 'reports.financial', 'Submitted / verified closes with discrepancies highlighted.'],
        'customer-ledger' => ['Customer Outstanding (Credit)', 'reports.financial', 'Unpaid and credit invoices with aging.'],
        'inventory-stock' => ['Stock on Hand', 'reports.financial', 'Current stock by branch, item and type.'],
        'inventory-consumption' => ['Inventory Consumption', 'reports.financial', 'Spares and consumables used per job and technician.'],
        'low-stock' => ['Low Stock / Reorder', 'reports.financial', 'Items at or below their reorder level.'],
        'inventory-valuation' => ['Inventory Valuation', 'reports.financial', 'Stock value (quantity × average cost) by branch and category.'],
        'detailed-summary' => ['Detailed Combined Report', 'reports.financial', 'Job + charges + items used + payment + technician, per visit.'],
        'assets' => ['Asset Register', 'reports.view', 'Company tools, vehicles and devices with status, condition, current holder and value.'],
        'expenses' => ['Expense Report', 'reports.financial', 'Expenses by date, category, payment method and branch.'],
        'profit-loss' => ['Income vs Expenses', 'reports.financial', 'Money collected against money spent, day by day, with net profit.'],
        'day-book' => ['Day Book', 'reports.financial', 'Every payment received and every expense paid, in order.'],
        'walk-in-sales' => ['Walk-in Sales', 'reports.financial', 'Counter bills: services and parts sold, discounts, paid and balance.'],
    ];

    private Tenant $tenant;

    private array $filters;

    public function run(string $key, array $filters, Tenant $tenant, ?int $limit = null): ReportResult
    {
        if (! isset(self::REPORTS[$key])) {
            throw new InvalidArgumentException("Unknown report [$key].");
        }
        $this->tenant = $tenant;
        $this->filters = $filters;
        $method = lcfirst(str_replace('-', '', ucwords($key, '-')));

        /** @var ReportResult $result */
        $result = $this->{$method}($limit);
        $result->title = self::REPORTS[$key][0];
        $result->subtitle = $this->describeFilters();

        return $result;
    }

    // ---- Reports ---------------------------------------------------------

    private function expenses(?int $limit): ReportResult
    {
        [$from, $to] = $this->localDates();
        $q = Expense::query()
            ->leftJoin('expense_categories as c', 'c.id', '=', 'expenses.expense_category_id')
            ->leftJoin('branches', 'branches.id', '=', 'expenses.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'expenses.user_id')
            ->whereBetween('expenses.expense_date', [$from, $to])
            ->when($this->f('branch_id'), fn ($q, $v) => $q->where('expenses.branch_id', $v))
            ->when($this->f('technician_id'), fn ($q, $v) => $q->where('expenses.user_id', $v))
            ->orderBy('expenses.expense_date')->orderBy('expenses.id');

        $byCategory = (clone $q)->reorder()->selectRaw("COALESCE(c.name, 'Uncategorised') as cat, SUM(expenses.amount) as t")->groupBy('cat')->orderByDesc('t')->pluck('t', 'cat');
        $rows = $q->limit($limit ?? PHP_INT_MAX)->get([
            'expenses.expense_date', 'c.name as category', 'expenses.paid_to', 'expenses.description', 'expenses.payment_method',
            'expenses.reference_no', 'branches.name as branch', 'u.name as staff', 'expenses.amount',
        ])->map(fn ($r) => [
            'date' => $r->expense_date?->format('Y-m-d'),
            'category' => $r->category ?? 'Uncategorised',
            'paid_to' => $r->paid_to,
            'description' => $r->description,
            'method' => str_replace('_', ' ', $r->payment_method),
            'reference' => $r->reference_no,
            'branch' => $r->branch,
            'staff' => $r->staff,
            'amount' => (int) $r->amount,
        ]);

        return new ReportResult([
            'date' => ['Date', 'date'], 'category' => ['Category'], 'paid_to' => ['Paid to'], 'description' => ['Description'],
            'method' => ['Method'], 'reference' => ['Reference'], 'branch' => ['Branch'], 'staff' => ['Staff'], 'amount' => ['Amount', 'money'],
        ], $rows->all(), ['Total expenses' => $this->money($byCategory->sum())] + $byCategory->take(5)->map(fn ($v) => $this->money($v))->all());
    }

    private function profitLoss(?int $limit): ReportResult
    {
        [$from, $to] = $this->localDates();
        $books = app(BooksService::class);
        $branch = $this->f('branch_id') ? (int) $this->f('branch_id') : null;
        $s = $books->summary($from, $to, $this->tenant->timezone, $branch);
        [$start, $end] = $books->bounds($from, $to, $this->tenant->timezone);
        $offset = CarbonImmutable::now($this->tenant->timezone)->format('P');
        $bySource = Payment::query()->where('payments.status', 'success')->whereBetween('payments.paid_at', [$start, $end])
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->when($branch, fn ($q, $v) => $q->where('invoices.branch_id', $v))
            ->selectRaw("DATE(CONVERT_TZ(payments.paid_at, '+00:00', ?)) as d, invoices.source, SUM(payments.amount) as t", [$offset])
            ->groupBy('d', 'invoices.source')->get()->groupBy('d');
        $expenseByDay = Expense::query()->whereBetween('expense_date', [$from, $to])
            ->when($branch, fn ($q, $v) => $q->where('branch_id', $v))
            ->selectRaw('DATE(expense_date) as d, SUM(amount) as t')->groupBy('d')->pluck('t', 'd');

        $rows = collect(CarbonPeriod::create($from, $to))->map(function ($day) use ($bySource, $expenseByDay) {
            $d = $day->format('Y-m-d');
            $src = ($bySource[$d] ?? collect())->pluck('t', 'source');
            $job = (int) ($src['job'] ?? 0);
            $walk = (int) ($src['walk_in'] ?? 0);
            $exp = (int) ($expenseByDay[$d] ?? 0);

            return ['date' => $d, 'service' => $job, 'walk_in' => $walk, 'income' => $job + $walk, 'expense' => $exp, 'net' => $job + $walk - $exp, '_flag' => $job + $walk - $exp < 0];
        })->filter(fn ($r) => $r['income'] || $r['expense'])->values()->take($limit ?? PHP_INT_MAX);

        return new ReportResult([
            'date' => ['Date', 'date'], 'service' => ['Service income', 'money'], 'walk_in' => ['Walk-in income', 'money'],
            'income' => ['Total income', 'money'], 'expense' => ['Expenses', 'money'], 'net' => ['Net', 'money'],
        ], $rows->all(), [
            'Income' => $this->money($s['income']),
            'Expenses' => $this->money($s['expense']),
            'Net profit' => $this->money($s['net']),
            'Cash in minus cash out' => $this->money($s['cash']['net']),
        ]);
    }

    private function dayBook(?int $limit): ReportResult
    {
        [$from, $to] = $this->localDates();
        $branch = $this->f('branch_id') ? (int) $this->f('branch_id') : null;
        $rows = collect(app(BooksService::class)->dayBook($from, $to, $this->tenant->timezone, $branch))
            ->map(fn ($r) => $r + ['method_label' => str_replace('_', ' ', $r['method'])])
            ->take($limit ?? PHP_INT_MAX);

        $in = $rows->sum('in');
        $out = $rows->sum('out');

        return new ReportResult([
            'at' => ['Date', 'datetime'], 'kind' => ['Type'], 'ref' => ['Receipt / ref'], 'party' => ['Customer / paid to'],
            'details' => ['Details'], 'method_label' => ['Method'], 'in' => ['In', 'money'], 'out' => ['Out', 'money'],
        ], $rows->all(), ['Money in' => $this->money($in), 'Money out' => $this->money($out), 'Net' => $this->money($in - $out)]);
    }

    private function walkInSales(?int $limit): ReportResult
    {
        $q = Invoice::query()->where('invoices.source', 'walk_in')
            ->leftJoin('customers as c', 'c.id', '=', 'invoices.customer_id')
            ->leftJoin('users as u', 'u.id', '=', 'invoices.created_by')
            ->leftJoin('branches', 'branches.id', '=', 'invoices.branch_id')
            ->tap(fn ($q) => $this->dateRange($q, 'invoices.generated_at'))
            ->when($this->f('branch_id'), fn ($q, $v) => $q->where('invoices.branch_id', $v))
            ->orderBy('invoices.generated_at');

        $rows = $q->limit($limit ?? PHP_INT_MAX)->get([
            'invoices.generated_at', 'invoices.invoice_number', 'c.name as customer', 'c.phone', 'invoices.total_service_charge',
            'invoices.total_spare_charge', 'invoices.discount_amount', 'invoices.total_amount', 'invoices.paid_amount', 'invoices.balance_amount',
            'branches.name as branch', 'u.name as billed_by',
        ])->map(fn ($r) => [
            'date' => $this->local($r->generated_at), 'invoice_number' => $r->invoice_number, 'customer' => $r->customer, 'phone' => $r->phone,
            'services' => $r->total_service_charge, 'parts' => $r->total_spare_charge, 'discount' => $r->discount_amount,
            'total' => $r->total_amount, 'paid' => $r->paid_amount, 'balance' => $r->balance_amount, 'branch' => $r->branch, 'billed_by' => $r->billed_by,
        ]);

        return new ReportResult([
            'date' => ['Date', 'datetime'], 'invoice_number' => ['Bill no.'], 'customer' => ['Customer'], 'phone' => ['Phone'],
            'services' => ['Services', 'money'], 'parts' => ['Parts', 'money'], 'discount' => ['Discount', 'money'], 'total' => ['Total', 'money'],
            'paid' => ['Paid', 'money'], 'balance' => ['Balance', 'money'], 'branch' => ['Branch'], 'billed_by' => ['Billed by'],
        ], $rows->all(), [
            'Bills' => $rows->count(),
            'Sales' => $this->money($rows->sum('total')),
            'Parts sold' => $this->money($rows->sum('parts')),
            'Discounts' => $this->money($rows->sum('discount')),
            'Outstanding' => $this->money($rows->sum('balance')),
        ]);
    }

    /** The report period as local Y-m-d dates (for DATE columns). @return array{0: string, 1: string} */
    private function localDates(): array
    {
        [$from, $to] = $this->range();
        $tz = $this->tenant->timezone;

        return [$from->timezone($tz)->toDateString(), $to->timezone($tz)->toDateString()];
    }

    private function assets(?int $limit): ReportResult
    {
        $q = Asset::query()
            ->leftJoin('users as h', 'h.id', '=', 'assets.assigned_to')
            ->leftJoin('branches', 'branches.id', '=', 'assets.branch_id')
            ->when($this->f('branch_id'), fn ($q, $v) => $q->where('assets.branch_id', $v))
            ->when($this->f('technician_id'), fn ($q, $v) => $q->where('assets.assigned_to', $v))
            ->when($this->f('status'), fn ($q, $v) => $q->where('assets.status', $v))
            ->orderBy('assets.asset_code');

        $summary = (clone $q)->reorder()->selectRaw('assets.status, COUNT(*) as c, COALESCE(SUM(assets.purchase_cost), 0) as v')->groupBy('assets.status')->get()->keyBy('status');
        $since = AssetAssignment::whereNull('returned_at')->pluck('issued_at', 'asset_id');

        $rows = $q->limit($limit ?? PHP_INT_MAX)->get([
            'assets.id', 'assets.asset_code', 'assets.name', 'assets.category', 'assets.brand', 'assets.model', 'assets.serial_no',
            'assets.status', 'assets.condition', 'h.name as holder', 'branches.name as branch', 'assets.purchase_date', 'assets.purchase_cost',
        ])->map(fn ($r) => [
            'asset_code' => $r->asset_code,
            'name' => $r->name,
            'category' => ucfirst($r->category),
            'make' => trim(($r->brand ?? '').' '.($r->model ?? '')) ?: null,
            'serial_no' => $r->serial_no,
            'status' => ucfirst(str_replace('_', ' ', $r->status)),
            'condition' => ucfirst($r->condition),
            'holder' => $r->holder,
            'since' => $this->local($since[$r->id] ?? null, 'Y-m-d'),
            'branch' => $r->branch,
            'purchase_date' => $r->purchase_date?->format('Y-m-d'),
            'cost' => (int) $r->purchase_cost,
        ]);

        $count = fn (string $s) => (int) ($summary[$s]->c ?? 0);

        return new ReportResult([
            'asset_code' => ['Code'], 'name' => ['Asset'], 'category' => ['Category'], 'make' => ['Brand / model'], 'serial_no' => ['Serial no.'],
            'status' => ['Status'], 'condition' => ['Condition'], 'holder' => ['Held by'], 'since' => ['Since', 'date'], 'branch' => ['Branch'],
            'purchase_date' => ['Purchased', 'date'], 'cost' => ['Cost', 'money'],
        ], $rows->all(), [
            'Total assets' => $summary->sum('c'),
            'Issued' => $count('assigned'),
            'Available' => $count('available'),
            'Under repair' => $count('under_repair'),
            'Lost' => $count('lost'),
            'Retired' => $count('retired'),
            'Total value' => $this->money($summary->sum('v')),
        ]);
    }

    private function jobs(?int $limit): ReportResult
    {
        $q = ServiceJob::query()
            ->leftJoin('customers', 'customers.id', '=', 'service_jobs.customer_id')
            ->leftJoin('users as t', 't.id', '=', 'service_jobs.assigned_technician_id')
            ->leftJoin('branches', 'branches.id', '=', 'service_jobs.branch_id')
            ->leftJoin('complaint_types', 'complaint_types.id', '=', 'service_jobs.complaint_type_id')
            ->tap(fn ($q) => $this->dateRange($q, 'service_jobs.created_at'))
            ->when($this->f('status'), fn ($q, $v) => $q->where('service_jobs.status', $v))
            ->when($this->f('technician_id'), fn ($q, $v) => $q->where('service_jobs.assigned_technician_id', $v))
            ->when($this->f('branch_id'), fn ($q, $v) => $q->where('service_jobs.branch_id', $v))
            ->when($this->f('priority'), fn ($q, $v) => $q->where('service_jobs.priority', $v))
            ->when($this->f('complaint_type_id'), fn ($q, $v) => $q->where('service_jobs.complaint_type_id', $v))
            ->orderBy('service_jobs.created_at');

        $summary = (clone $q)->reorder()->selectRaw('service_jobs.status, COUNT(*) as c')->groupBy('service_jobs.status')->pluck('c', 'status');

        $rows = $q->limit($limit ?? PHP_INT_MAX)->get([
            'service_jobs.crm_call_id', 'service_jobs.created_at', 'customers.name as customer', 'customers.phone',
            'complaint_types.name as complaint', 'service_jobs.priority', 'service_jobs.call_type', 'service_jobs.status',
            't.name as technician', 'branches.name as branch', 'service_jobs.scheduled_at', 'service_jobs.completed_at', 'service_jobs.cancelled_at',
        ])->map(fn ($r) => [
            'crm_call_id' => $r->crm_call_id,
            'created_at' => $this->local($r->created_at),
            'customer' => $r->customer,
            'phone' => $r->phone,
            'complaint' => $r->complaint,
            'priority' => ucfirst($r->priority),
            'call_type' => str_replace('_', ' ', $r->call_type),
            'status' => str_replace('_', ' ', $r->status->value),
            'technician' => $r->technician,
            'branch' => $r->branch,
            'scheduled_at' => $this->local($r->scheduled_at),
            'completed_at' => $this->local($r->completed_at),
            'age_days' => $r->call_age_days,
        ]);

        return new ReportResult([
            'crm_call_id' => ['Call ID'], 'created_at' => ['Created', 'datetime'], 'customer' => ['Customer'], 'phone' => ['Phone'],
            'complaint' => ['Complaint'], 'priority' => ['Priority'], 'call_type' => ['Call Type'], 'status' => ['Status'],
            'technician' => ['Technician'], 'branch' => ['Branch'], 'scheduled_at' => ['Scheduled', 'datetime'],
            'completed_at' => ['Completed', 'datetime'], 'age_days' => ['Age (days)', 'number'],
        ], $rows->all(), [
            'Total jobs' => array_sum($summary->all()),
            'Open' => (int) ($summary['open'] ?? 0), 'In progress' => (int) ($summary['in_progress'] ?? 0),
            'Pending' => (int) ($summary['pending'] ?? 0), 'Completed' => (int) ($summary['completed'] ?? 0),
            'Cancelled' => (int) ($summary['cancelled'] ?? 0),
        ]);
    }

    private function technicianPerformance(?int $limit): ReportResult
    {
        [$from, $to] = $this->range();
        $techs = User::inTenant($this->tenant->id)->technicians()
            ->when($this->f('branch_id'), fn ($q, $v) => $q->where('branch_id', $v))
            ->when($this->f('technician_id'), fn ($q, $v) => $q->whereKey($v))
            ->orderBy('name')->get(['id', 'name']);
        $ids = $techs->pluck('id');

        $jobs = ServiceJob::whereIn('assigned_technician_id', $ids)->whereBetween('created_at', [$from, $to])
            ->selectRaw("assigned_technician_id as id, COUNT(*) as assigned, SUM(status = 'completed') as completed, SUM(status IN ('open','in_progress','pending')) as open_jobs")
            ->groupBy('assigned_technician_id')->get()->keyBy('id');
        $visits = JobVisit::whereIn('technician_id', $ids)->whereBetween('start_time', [$from, $to])->whereNotNull('end_time')
            ->selectRaw('technician_id as id, COUNT(*) as visits, AVG(duration_seconds) as avg_duration, SUM(total_charge) as billed')
            ->groupBy('technician_id')->get()->keyBy('id');
        $ratings = Review::whereIn('technician_id', $ids)->whereBetween('created_at', [$from, $to])
            ->selectRaw('technician_id as id, AVG(rating) as rating, COUNT(*) as reviews')->groupBy('technician_id')->get()->keyBy('id');
        $cash = Payment::whereIn('collected_by', $ids)->where('status', 'success')->whereBetween('paid_at', [$from, $to])
            ->whereIn('method', ['cash', 'cheque'])->selectRaw('collected_by as id, SUM(amount) as total')->groupBy('collected_by')->pluck('total', 'id');
        $deposited = DB::table('cash_deposits')->where('tenant_id', $this->tenant->id)->whereIn('technician_id', $ids)
            ->whereBetween('deposit_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('technician_id as id, SUM(amount) as total')->groupBy('technician_id')->pluck('total', 'id');

        $rows = $techs->map(fn ($t) => [
            'technician' => $t->name,
            'assigned' => (int) ($jobs[$t->id]->assigned ?? 0),
            'completed' => (int) ($jobs[$t->id]->completed ?? 0),
            'open' => (int) ($jobs[$t->id]->open_jobs ?? 0),
            'visits' => (int) ($visits[$t->id]->visits ?? 0),
            'avg_minutes' => isset($visits[$t->id]) && $visits[$t->id]->avg_duration ? (int) round($visits[$t->id]->avg_duration / 60) : 0,
            'rating' => isset($ratings[$t->id]) ? round((float) $ratings[$t->id]->rating, 1) : null,
            'reviews' => (int) ($ratings[$t->id]->reviews ?? 0),
            'billed' => (int) ($visits[$t->id]->billed ?? 0),
            'cash_collected' => (int) ($cash[$t->id] ?? 0),
            'cash_deposited' => (int) ($deposited[$t->id] ?? 0),
        ]);

        return new ReportResult([
            'technician' => ['Technician'], 'assigned' => ['Assigned', 'number'], 'completed' => ['Completed', 'number'],
            'open' => ['Open', 'number'], 'visits' => ['Visits', 'number'], 'avg_minutes' => ['Avg visit (min)', 'number'],
            'rating' => ['Avg rating', 'number'], 'reviews' => ['Reviews', 'number'], 'billed' => ['Billed', 'money'],
            'cash_collected' => ['Cash+cheque collected', 'money'], 'cash_deposited' => ['Deposited', 'money'],
        ], $rows->all(), [
            'Jobs completed' => $rows->sum('completed'),
            'Total billed' => $this->money($rows->sum('billed')),
            'Cash collected' => $this->money($rows->sum('cash_collected')),
        ]);
    }

    private function revenue(?int $limit): ReportResult
    {
        [$from, $to] = $this->range();
        $group = in_array($this->f('group_by'), ['technician', 'branch', 'month'], true) ? $this->f('group_by') : 'day';
        $tzOffset = CarbonImmutable::now($this->tenant->timezone)->format('P');

        $groupExpr = match ($group) {
            'technician' => "COALESCE(u.name, 'Office / Online')",
            'branch' => "COALESCE(b.name, '-')",
            'month' => "DATE_FORMAT(CONVERT_TZ(payments.paid_at, '+00:00', '$tzOffset'), '%Y-%m')",
            default => "DATE(CONVERT_TZ(payments.paid_at, '+00:00', '$tzOffset'))",
        };

        $rows = Payment::query()
            ->join('invoices as i', 'i.id', '=', 'payments.invoice_id')
            ->leftJoin('users as u', 'u.id', '=', 'payments.collected_by')
            ->leftJoin('branches as b', 'b.id', '=', 'i.branch_id')
            ->where('payments.status', 'success')
            ->whereBetween('payments.paid_at', [$from, $to])
            ->when($this->f('branch_id'), fn ($q, $v) => $q->where('i.branch_id', $v))
            ->when($this->f('technician_id'), fn ($q, $v) => $q->where('payments.collected_by', $v))
            ->groupByRaw($groupExpr)
            ->orderByRaw($groupExpr)
            ->selectRaw("$groupExpr as grp,
                SUM(CASE WHEN payments.method = 'cash' THEN payments.amount ELSE 0 END) as cash,
                SUM(CASE WHEN payments.method = 'upi' THEN payments.amount ELSE 0 END) as upi,
                SUM(CASE WHEN payments.method = 'cheque' THEN payments.amount ELSE 0 END) as cheque,
                SUM(CASE WHEN payments.method = 'bank_transfer' THEN payments.amount ELSE 0 END) as bank,
                SUM(CASE WHEN payments.method = 'online' THEN payments.amount ELSE 0 END) as online,
                SUM(payments.amount) as total, COUNT(*) as receipts")
            ->get()
            ->map(fn ($r) => ['group' => (string) $r->grp, 'receipts' => (int) $r->receipts, 'cash' => (int) $r->cash, 'upi' => (int) $r->upi,
                'cheque' => (int) $r->cheque, 'bank' => (int) $r->bank, 'online' => (int) $r->online, 'total' => (int) $r->total]);

        $invoices = Invoice::whereBetween('generated_at', [$from, $to])->when($this->f('branch_id'), fn ($q, $v) => $q->where('branch_id', $v));
        $billed = (int) (clone $invoices)->sum('total_amount');
        $pending = (int) (clone $invoices)->sum('balance_amount');
        $credit = (int) (clone $invoices)->where('is_credit', true)->sum('balance_amount');

        return new ReportResult([
            'group' => [ucfirst($group)], 'receipts' => ['Receipts', 'number'], 'cash' => ['Cash', 'money'], 'upi' => ['UPI', 'money'],
            'cheque' => ['Cheque', 'money'], 'bank' => ['Bank transfer', 'money'], 'online' => ['Online', 'money'], 'total' => ['Total collected', 'money'],
        ], $rows->all(), [
            'Collected' => $this->money($rows->sum('total')),
            'Billed in period' => $this->money($billed),
            'Pending (period invoices)' => $this->money($pending),
            'Of which credit' => $this->money($credit),
        ]);
    }

    private function cashCollection(?int $limit): ReportResult
    {
        [$from, $to] = $this->range();
        $tzOffset = CarbonImmutable::now($this->tenant->timezone)->format('P');
        $day = "DATE(CONVERT_TZ(payments.paid_at, '+00:00', '$tzOffset'))";

        $rows = Payment::query()
            ->join('users as u', 'u.id', '=', 'payments.collected_by')
            ->leftJoin('branches as b', 'b.id', '=', 'u.branch_id')
            ->where('payments.status', 'success')
            ->whereIn('payments.method', ['cash', 'upi', 'cheque', 'bank_transfer'])
            ->where('u.role', 'technician')
            ->whereBetween('payments.paid_at', [$from, $to])
            ->when($this->f('technician_id'), fn ($q, $v) => $q->where('payments.collected_by', $v))
            ->when($this->f('branch_id'), fn ($q, $v) => $q->where('u.branch_id', $v))
            ->groupByRaw("$day, u.id, u.name, b.name")
            ->orderByRaw("$day, u.name")
            ->selectRaw("$day as day, u.name as technician, b.name as branch,
                SUM(CASE WHEN payments.method = 'cash' THEN payments.amount ELSE 0 END) as cash,
                SUM(CASE WHEN payments.method = 'cheque' THEN payments.amount ELSE 0 END) as cheque,
                SUM(CASE WHEN payments.method IN ('upi','bank_transfer') THEN payments.amount ELSE 0 END) as digital,
                SUM(payments.amount) as total,
                SUM(payments.cash_close_id IS NULL) as unclosed")
            ->get()
            ->map(fn ($r) => ['day' => (string) $r->day, 'technician' => $r->technician, 'branch' => $r->branch, 'cash' => (int) $r->cash,
                'cheque' => (int) $r->cheque, 'digital' => (int) $r->digital, 'total' => (int) $r->total,
                'closed' => $r->unclosed > 0 ? 'Not closed' : 'Closed', '_flag' => $r->unclosed > 0]);

        return new ReportResult([
            'day' => ['Date', 'date'], 'technician' => ['Technician'], 'branch' => ['Branch'], 'cash' => ['Cash', 'money'],
            'cheque' => ['Cheque', 'money'], 'digital' => ['UPI / Bank', 'money'], 'total' => ['Total', 'money'], 'closed' => ['Cash close'],
        ], $rows->all(), [
            'Cash' => $this->money($rows->sum('cash')), 'Cheque' => $this->money($rows->sum('cheque')),
            'UPI / Bank' => $this->money($rows->sum('digital')), 'Total' => $this->money($rows->sum('total')),
        ]);
    }

    private function cashClose(?int $limit): ReportResult
    {
        [$from, $to] = $this->range();
        $rows = CashClose::with(['technician:id,name', 'branch:id,name', 'verifier:id,name'])
            ->whereBetween('close_date', [$from->timezone($this->tenant->timezone)->toDateString(), $to->timezone($this->tenant->timezone)->toDateString()])
            ->when($this->f('technician_id'), fn ($q, $v) => $q->where('technician_id', $v))
            ->when($this->f('branch_id'), fn ($q, $v) => $q->where('branch_id', $v))
            ->when($this->f('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderBy('close_date')->limit($limit ?? PHP_INT_MAX)->get()
            ->map(fn (CashClose $c) => [
                'date' => $c->close_date->toDateString(), 'technician' => $c->technician?->name, 'branch' => $c->branch?->name,
                'opening' => $c->opening_balance, 'cash' => $c->total_cash_collected, 'cheque' => $c->total_cheque_collected,
                'expected' => $c->expected_in_hand, 'declared' => $c->amount_confirmed, 'verified' => $c->amount_verified,
                'discrepancy' => $c->discrepancy_amount, 'deposited' => $c->total_deposited, 'carry_forward' => $c->closing_balance,
                'status' => ucfirst($c->status).($c->force_closed ? ' (forced)' : ''), 'remarks' => $c->discrepancy_remarks ?? $c->technician_remarks,
                'verified_by' => $c->verifier?->name,
                '_flag' => $c->discrepancy_amount !== 0 || $c->amount_confirmed !== $c->expected_in_hand,
            ]);

        return new ReportResult([
            'date' => ['Date', 'date'], 'technician' => ['Technician'], 'branch' => ['Branch'], 'opening' => ['Opening', 'money'],
            'cash' => ['Cash', 'money'], 'cheque' => ['Cheque', 'money'], 'expected' => ['Expected', 'money'], 'declared' => ['Declared', 'money'],
            'verified' => ['Verified', 'money'], 'discrepancy' => ['Discrepancy', 'money'], 'deposited' => ['Deposited', 'money'],
            'carry_forward' => ['Carry fwd', 'money'], 'status' => ['Status'], 'remarks' => ['Remarks'], 'verified_by' => ['Verified by'],
        ], $rows->all(), [
            'Closes' => $rows->count(),
            'With discrepancy' => $rows->where('_flag', true)->count(),
            'Net discrepancy' => $this->money($rows->sum('discrepancy')),
            'Deposited' => $this->money($rows->sum('deposited')),
        ]);
    }

    private function customerLedger(?int $limit): ReportResult
    {
        $rows = Invoice::query()
            ->join('customers as c', 'c.id', '=', 'invoices.customer_id')
            ->leftJoin('service_jobs as j', 'j.id', '=', 'invoices.job_id')
            ->where('invoices.balance_amount', '>', 0)
            ->when($this->f('credit_only'), fn ($q) => $q->where('invoices.is_credit', true))
            ->when($this->f('branch_id'), fn ($q, $v) => $q->where('invoices.branch_id', $v))
            ->orderBy('invoices.generated_at')
            ->limit($limit ?? PHP_INT_MAX)
            ->get(['invoices.invoice_number', 'invoices.generated_at', 'invoices.total_amount', 'invoices.paid_amount', 'invoices.balance_amount',
                'invoices.is_credit', 'c.name as customer', 'c.phone', 'j.crm_call_id'])
            ->map(function ($r) {
                $days = (int) $r->generated_at->diffInDays(now());

                return [
                    'customer' => $r->customer, 'phone' => $r->phone, 'invoice_number' => $r->invoice_number, 'call_id' => $r->crm_call_id ?? 'Walk-in',
                    'date' => $this->local($r->generated_at, 'Y-m-d'), 'type' => $r->is_credit ? 'Credit' : 'Unpaid',
                    'total' => $r->total_amount, 'paid' => $r->paid_amount, 'balance' => $r->balance_amount, 'age_days' => $days,
                    'bucket' => match (true) {
                        $days <= 30 => '0-30', $days <= 60 => '31-60', $days <= 90 => '61-90', default => '90+'
                    },
                    '_flag' => $days > 60,
                ];
            });

        $buckets = $rows->groupBy('bucket')->map(fn ($g) => $g->sum('balance'));

        return new ReportResult([
            'customer' => ['Customer'], 'phone' => ['Phone'], 'invoice_number' => ['Invoice'], 'call_id' => ['Call ID'],
            'date' => ['Invoice date', 'date'], 'type' => ['Type'], 'total' => ['Total', 'money'], 'paid' => ['Paid', 'money'],
            'balance' => ['Balance', 'money'], 'age_days' => ['Age (days)', 'number'], 'bucket' => ['Aging'],
        ], $rows->all(), [
            'Outstanding' => $this->money($rows->sum('balance')),
            '0-30 days' => $this->money($buckets['0-30'] ?? 0), '31-60 days' => $this->money($buckets['31-60'] ?? 0),
            '61-90 days' => $this->money($buckets['61-90'] ?? 0), '90+ days' => $this->money($buckets['90+'] ?? 0),
        ]);
    }

    private function inventoryStock(?int $limit, bool $lowOnly = false): ReportResult
    {
        $rows = $this->stockQuery()
            ->when($lowOnly, fn ($q) => $q->where('i.reorder_level', '>', 0)->whereColumn('inventory_stock.quantity_available', '<=', 'i.reorder_level'))
            ->orderBy('b.name')->orderBy('i.name')->limit($limit ?? PHP_INT_MAX)->get()
            ->map(fn ($r) => [
                'branch' => $r->branch, 'code' => $r->code, 'name' => $r->name, 'type' => ucfirst($r->type), 'category' => $r->category,
                'quantity' => (float) $r->quantity_available, 'uom' => $r->unit_of_measure, 'reorder_level' => (float) $r->reorder_level,
                'avg_cost' => (int) $r->avg_unit_cost, 'value' => (int) round($r->quantity_available * $r->avg_unit_cost),
                '_flag' => $r->reorder_level > 0 && $r->quantity_available <= $r->reorder_level,
            ]);

        return new ReportResult([
            'branch' => ['Branch'], 'code' => ['Code'], 'name' => ['Item'], 'type' => ['Type'], 'category' => ['Category'],
            'quantity' => ['Qty', 'quantity'], 'uom' => ['Unit'], 'reorder_level' => ['Reorder at', 'quantity'],
            'avg_cost' => ['Avg cost', 'money'], 'value' => ['Value', 'money'],
        ], $rows->all(), [
            'Line items' => $rows->count(),
            'Low stock' => $rows->where('_flag', true)->count(),
            'Stock value' => $this->money($rows->sum('value')),
        ]);
    }

    private function lowStock(?int $limit): ReportResult
    {
        return $this->inventoryStock($limit, true);
    }

    private function inventoryValuation(?int $limit): ReportResult
    {
        $rows = $this->stockQuery()
            ->groupBy('b.name', 'i.category', 'i.type')
            ->orderBy('b.name')->orderBy('i.category')
            ->select([DB::raw('b.name as branch'), 'i.category', 'i.type', DB::raw('COUNT(*) as items'),
                DB::raw('SUM(ROUND(inventory_stock.quantity_available * inventory_stock.avg_unit_cost)) as value')])
            ->get()
            ->map(fn ($r) => ['branch' => $r->branch, 'category' => $r->category ?: 'Uncategorised', 'type' => ucfirst($r->type), 'items' => (int) $r->items, 'value' => (int) $r->value]);

        return new ReportResult([
            'branch' => ['Branch'], 'category' => ['Category'], 'type' => ['Type'], 'items' => ['Items', 'number'], 'value' => ['Value', 'money'],
        ], $rows->all(), [
            'Total value' => $this->money($rows->sum('value')),
            'Spares' => $this->money($rows->where('type', 'Spare')->sum('value')),
            'Consumables' => $this->money($rows->where('type', 'Consumable')->sum('value')),
        ]);
    }

    private function inventoryConsumption(?int $limit): ReportResult
    {
        $rows = JobInventoryUsage::query()
            ->join('inventory_items as i', 'i.id', '=', 'job_inventory_usage.item_id')
            ->join('job_visits as v', 'v.id', '=', 'job_inventory_usage.job_visit_id')
            ->join('service_jobs as j', 'j.id', '=', 'job_inventory_usage.job_id')
            ->join('users as u', 'u.id', '=', 'v.technician_id')
            ->leftJoin('branches as b', 'b.id', '=', 'job_inventory_usage.branch_id')
            ->tap(fn ($q) => $this->dateRange($q, 'job_inventory_usage.created_at'))
            ->when($this->f('technician_id'), fn ($q, $v) => $q->where('v.technician_id', $v))
            ->when($this->f('branch_id'), fn ($q, $v) => $q->where('job_inventory_usage.branch_id', $v))
            ->when($this->f('type'), fn ($q, $v) => $q->where('i.type', $v))
            ->when($this->f('item_id'), fn ($q, $v) => $q->where('i.id', $v))
            ->orderBy('job_inventory_usage.created_at')
            ->limit($limit ?? PHP_INT_MAX)
            ->get(['job_inventory_usage.created_at', 'j.crm_call_id', 'u.name as technician', 'b.name as branch', 'i.code', 'i.name', 'i.type',
                'job_inventory_usage.quantity', 'i.unit_of_measure', 'job_inventory_usage.unit_price', 'job_inventory_usage.total_price'])
            ->map(fn ($r) => [
                'date' => $this->local($r->created_at), 'call_id' => $r->crm_call_id, 'technician' => $r->technician, 'branch' => $r->branch,
                'code' => $r->code, 'item' => $r->name, 'type' => ucfirst($r->type), 'quantity' => (float) $r->quantity, 'uom' => $r->unit_of_measure,
                'unit_price' => (int) $r->unit_price, 'total' => (int) $r->total_price,
            ]);

        return new ReportResult([
            'date' => ['Date', 'datetime'], 'call_id' => ['Call ID'], 'technician' => ['Technician'], 'branch' => ['Branch'], 'code' => ['Code'],
            'item' => ['Item'], 'type' => ['Type'], 'quantity' => ['Qty', 'quantity'], 'uom' => ['Unit'], 'unit_price' => ['Rate', 'money'], 'total' => ['Amount', 'money'],
        ], $rows->all(), [
            'Lines' => $rows->count(),
            'Spares value' => $this->money($rows->where('type', 'Spare')->sum('total')),
            'Consumables value' => $this->money($rows->where('type', 'Consumable')->sum('total')),
        ]);
    }

    private function detailedSummary(?int $limit): ReportResult
    {
        $visits = JobVisit::with([
            'job:id,crm_call_id,customer_id,branch_id,complaint_type_id,status', 'job.customer:id,name,phone', 'job.branch:id,name',
            'job.complaintType:id,name', 'technician:id,name', 'actionTaken:id,name', 'inventoryUsage.item:id,name,unit_of_measure',
            'payments' => fn ($q) => $q->where('status', 'success'),
        ])
            ->whereNotNull('end_time')
            ->tap(fn ($q) => $this->dateRange($q, 'start_time'))
            ->when($this->f('technician_id'), fn ($q, $v) => $q->where('technician_id', $v))
            ->when($this->f('branch_id'), fn ($q, $v) => $q->whereHas('job', fn ($j) => $j->where('branch_id', $v)))
            ->orderBy('start_time')
            ->limit($limit ?? PHP_INT_MAX)
            ->get();

        $rows = $visits->map(fn (JobVisit $v) => [
            'date' => $this->local($v->start_time),
            'call_id' => $v->job?->crm_call_id,
            'customer' => $v->job?->customer?->name,
            'phone' => $v->job?->customer?->phone,
            'branch' => $v->job?->branch?->name,
            'complaint' => $v->job?->complaintType?->name,
            'technician' => $v->technician?->name,
            'service_type' => str_replace('_', ' ', $v->service_type),
            'duration' => $v->duration_seconds ? (int) round($v->duration_seconds / 60) : null,
            'visit_status' => ucfirst($v->status),
            'action_taken' => $v->actionTaken?->name,
            'items_used' => $v->inventoryUsage->map(fn ($u) => $u->item?->name.' × '.rtrim(rtrim(number_format($u->quantity, 3, '.', ''), '0'), '.').' '.$u->item?->unit_of_measure)->implode('; '),
            'labour' => $v->labour_charge,
            'spare' => $v->spare_charge,
            'total' => $v->total_charge,
            'payment_method' => $v->payment_method ? str_replace('_', ' ', $v->payment_method) : null,
            'collected' => (int) $v->payments->sum('amount'),
            'receipts' => $v->payments->pluck('receipt_number')->filter()->implode(', '),
        ]);

        return new ReportResult([
            'date' => ['Date', 'datetime'], 'call_id' => ['Call ID'], 'customer' => ['Customer'], 'phone' => ['Phone'], 'branch' => ['Branch'],
            'complaint' => ['Complaint'], 'technician' => ['Technician'], 'service_type' => ['Service type'], 'duration' => ['Minutes', 'number'],
            'visit_status' => ['Visit status'], 'action_taken' => ['Action taken'], 'items_used' => ['Spares / consumables used'],
            'labour' => ['Labour', 'money'], 'spare' => ['Spare', 'money'], 'total' => ['Total', 'money'], 'payment_method' => ['Payment'],
            'collected' => ['Collected', 'money'], 'receipts' => ['Receipts'],
        ], $rows->all(), [
            'Visits' => $rows->count(),
            'Labour' => $this->money($rows->sum('labour')),
            'Spares' => $this->money($rows->sum('spare')),
            'Total charged' => $this->money($rows->sum('total')),
            'Collected on visit' => $this->money($rows->sum('collected')),
        ]);
    }

    // ---- Helpers ---------------------------------------------------------

    private function stockQuery()
    {
        return InventoryStock::query()
            ->join('inventory_items as i', 'i.id', '=', 'inventory_stock.item_id')
            ->join('branches as b', 'b.id', '=', 'inventory_stock.branch_id')
            ->when($this->f('branch_id'), fn ($q, $v) => $q->where('inventory_stock.branch_id', $v))
            ->when($this->f('type'), fn ($q, $v) => $q->where('i.type', $v))
            ->select(['inventory_stock.*', 'b.name as branch', 'i.code', 'i.name', 'i.type', 'i.category', 'i.unit_of_measure', 'i.reorder_level']);
    }

    /** Period bounds in UTC from tenant-local from/to dates (default: current month). */
    private function range(): array
    {
        $tz = $this->tenant->timezone;
        $from = $this->f('from') ? CarbonImmutable::parse($this->f('from'), $tz)->startOfDay() : CarbonImmutable::now($tz)->startOfMonth();
        $to = $this->f('to') ? CarbonImmutable::parse($this->f('to'), $tz)->endOfDay() : CarbonImmutable::now($tz)->endOfDay();

        return [$from->utc(), $to->utc()];
    }

    private function dateRange($query, string $column): void
    {
        [$from, $to] = $this->range();
        $query->whereBetween($column, [$from, $to]);
    }

    private function f(string $key): mixed
    {
        $value = $this->filters[$key] ?? null;

        return $value === '' ? null : $value;
    }

    private function local($date, string $format = 'Y-m-d H:i'): ?string
    {
        return $date ? CarbonImmutable::parse($date)->timezone($this->tenant->timezone)->format($format) : null;
    }

    private function money(int|float $minor): string
    {
        return Money::format((int) $minor, $this->tenant->currency);
    }

    private function describeFilters(): string
    {
        [$from, $to] = $this->range();
        $tz = $this->tenant->timezone;

        return $from->timezone($tz)->format('d M Y').' – '.$to->timezone($tz)->format('d M Y');
    }
}
