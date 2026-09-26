<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashClose;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\JobVisit;
use App\Models\Payment;
use App\Models\Review;
use App\Models\ServiceJob;
use App\Models\User;
use App\Services\CashCloseService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /** FR-3.2 tenant KPIs (cached briefly to keep the dashboard fast under load). */
    public function tenant(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();
        $branchId = $request->integer('branch_id') ?: null;
        $key = "dashboard:{$tenant->id}:".($branchId ?? 'all');

        $data = Cache::remember($key, 60, fn () => $this->build($tenant->timezone, $branchId));

        return $this->ok($data);
    }

    /** FR-3.1 technician home: own counters, today's jobs, cash in hand, active visit. */
    public function technician(Request $request, CashCloseService $cash): JsonResponse
    {
        $user = $request->user();
        $tz = app(TenantContext::class)->tenant()->timezone;
        $counts = ServiceJob::where('assigned_technician_id', $user->id)
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $today = ServiceJob::where('assigned_technician_id', $user->id)
            ->whereIn('status', ['open', 'pending', 'in_progress'])
            ->where(fn ($q) => $q->whereBetween('scheduled_at', [now($tz)->startOfDay()->utc(), now($tz)->endOfDay()->utc()])
                ->orWhere('scheduled_at', '<', now($tz)->startOfDay()->utc()))
            ->with(['customer:id,name,phone,address,city', 'complaintType:id,name'])
            ->orderBy('scheduled_at')->limit(20)->get();

        return $this->ok([
            'counts' => [
                'open' => (int) ($counts['open'] ?? 0),
                'in_progress' => (int) ($counts['in_progress'] ?? 0),
                'pending' => (int) ($counts['pending'] ?? 0),
                'completed' => (int) ($counts['completed'] ?? 0),
            ],
            'today' => $today,
            'punch_status' => $user->punch_status,
            'punched_at' => $user->punched_at,
            'cash_in_hand' => $cash->cashInHand($user),
            'active_visit' => JobVisit::where('technician_id', $user->id)->where('status', 'in_progress')->with('job:id,crm_call_id')->first(),
            'collected_today' => (int) Payment::where('collected_by', $user->id)->where('status', 'success')
                ->where('paid_at', '>=', now($tz)->startOfDay()->utc())->sum('amount'),
        ]);
    }

    private function build(string $tz, ?int $branchId): array
    {
        $jobs = fn () => ServiceJob::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $monthStart = now($tz)->startOfMonth()->utc();
        $todayStart = now($tz)->startOfDay()->utc();
        $todayEnd = now($tz)->endOfDay()->utc();

        $status = $jobs()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $payments = Payment::where('status', 'success')->where('paid_at', '>=', $monthStart)
            ->when($branchId, fn ($q) => $q->whereHas('invoice', fn ($i) => $i->where('branch_id', $branchId)));
        $byMethod = (clone $payments)->selectRaw('method, SUM(amount) as total')->groupBy('method')->pluck('total', 'method');

        $invoices = Invoice::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        // Jobs created vs completed – last 14 days.
        $since = now($tz)->subDays(13)->startOfDay()->utc();
        $created = $jobs()->where('created_at', '>=', $since)->get(['created_at'])
            ->groupBy(fn ($j) => $j->created_at->timezone($tz)->toDateString())->map->count();
        $completed = $jobs()->where('completed_at', '>=', $since)->get(['completed_at'])
            ->groupBy(fn ($j) => $j->completed_at->timezone($tz)->toDateString())->map->count();
        $trend = collect(range(13, 0))->map(function ($d) use ($tz, $created, $completed) {
            $date = now($tz)->subDays($d)->toDateString();

            return ['date' => $date, 'created' => $created[$date] ?? 0, 'completed' => $completed[$date] ?? 0];
        })->values();

        // Technician performance this month.
        $perf = JobVisit::query()
            ->join('users', 'users.id', '=', 'job_visits.technician_id')
            ->where('job_visits.start_time', '>=', $monthStart)
            ->when($branchId, fn ($q) => $q->where('users.branch_id', $branchId))
            ->groupBy('job_visits.technician_id', 'users.name')
            ->selectRaw("job_visits.technician_id, users.name, SUM(job_visits.status = 'completed') as completed, COUNT(*) as visits, AVG(job_visits.duration_seconds) as avg_duration, SUM(job_visits.total_charge) as revenue")
            ->orderByDesc('completed')->limit(8)->get();
        $ratings = Review::whereIn('technician_id', $perf->pluck('technician_id'))->groupBy('technician_id')
            ->selectRaw('technician_id, AVG(rating) as rating')->pluck('rating', 'technician_id');

        $lowStock = InventoryStock::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'inventory_stock.item_id')
            ->join('branches', 'branches.id', '=', 'inventory_stock.branch_id')
            ->where('inventory_items.reorder_level', '>', 0)
            ->whereColumn('inventory_stock.quantity_available', '<=', 'inventory_items.reorder_level')
            ->when($branchId, fn ($q) => $q->where('inventory_stock.branch_id', $branchId))
            ->select(['inventory_items.id', 'inventory_items.code', 'inventory_items.name', 'inventory_items.unit_of_measure', 'inventory_items.reorder_level', 'inventory_stock.quantity_available', 'branches.name as branch_name']);

        $technicians = User::inTenant()->technicians()->where('status', 'active')->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        return [
            'jobs' => [
                'by_status' => collect(['open', 'in_progress', 'pending', 'completed', 'cancelled'])->mapWithKeys(fn ($s) => [$s => (int) ($status[$s] ?? 0)]),
                'unassigned' => $jobs()->whereIn('status', ['open', 'pending'])->whereNull('assigned_technician_id')->count(),
                'scheduled_today' => $jobs()->whereBetween('scheduled_at', [$todayStart, $todayEnd])->count(),
                'overdue' => $jobs()->whereIn('status', ['open', 'pending'])->where('scheduled_at', '<', $todayStart)->count(),
                'created_this_month' => $jobs()->where('created_at', '>=', $monthStart)->count(),
                'trend' => $trend,
            ],
            'revenue' => [
                'collected_this_month' => (int) (clone $payments)->sum('amount'),
                'by_method' => $byMethod->map(fn ($v) => (int) $v),
                'outstanding' => (int) (clone $invoices)->sum('balance_amount'),
                'credit_outstanding' => (int) (clone $invoices)->where('is_credit', true)->sum('balance_amount'),
                'invoiced_this_month' => (int) (clone $invoices)->where('generated_at', '>=', $monthStart)->sum('total_amount'),
            ],
            'technicians' => [
                'total' => (clone $technicians)->count(),
                'on_duty' => (clone $technicians)->where('punch_status', 'in')->count(),
                'performance' => $perf->map(fn ($r) => [
                    'id' => $r->technician_id,
                    'name' => $r->name,
                    'completed' => (int) $r->completed,
                    'visits' => (int) $r->visits,
                    'avg_duration_minutes' => $r->avg_duration ? round($r->avg_duration / 60) : null,
                    'revenue' => (int) $r->revenue,
                    'rating' => isset($ratings[$r->technician_id]) ? round((float) $ratings[$r->technician_id], 1) : null,
                ]),
            ],
            'inventory' => [
                'low_stock_count' => (clone $lowStock)->count(),
                'low_stock' => (clone $lowStock)->orderBy('inventory_stock.quantity_available')->limit(6)->get(),
            ],
            'cash' => [
                'pending_verification' => CashClose::where('status', 'submitted')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->count(),
                'discrepancies_this_month' => CashClose::where('close_date', '>=', now($tz)->startOfMonth()->toDateString())->where('discrepancy_amount', '!=', 0)->count(),
            ],
            'rating' => round((float) Review::where('created_at', '>=', $monthStart)->avg('rating'), 1),
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
