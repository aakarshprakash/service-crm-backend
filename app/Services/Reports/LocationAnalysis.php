<?php

namespace App\Services\Reports;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\ServiceJob;
use App\Models\ServiceLocation;
use App\Models\User;
use App\Services\AutoAssigner;
use Carbon\CarbonInterface;

/**
 * Service-location view of the work: what is on each location's plate right now, who it is
 * allocated to, and how the location did over a period. Jobs without a location get their own row.
 */
class LocationAnalysis
{
    /**
     * Jobs logged and completed over the period, per day (per month when the period is long).
     *
     * @return array{bucket: string, points: list<array{date: string, logged: int, completed: int}>}
     */
    public function trend(string $tz, ?int $branchId, CarbonInterface $from, CarbonInterface $to): array
    {
        $first = $from->copy()->timezone($tz)->startOfDay();
        $last = $to->copy()->timezone($tz)->startOfDay();
        $monthly = $first->diffInDays($last) > 62;
        $bucket = fn (CarbonInterface $d) => $d->copy()->timezone($tz)->format($monthly ? 'Y-m' : 'Y-m-d');
        $jobs = fn () => ServiceJob::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $logged = $jobs()->whereBetween('created_at', [$from, $to])->get(['created_at'])->groupBy(fn ($j) => $bucket($j->created_at))->map->count();
        $completed = $jobs()->where('status', 'completed')->whereBetween('completed_at', [$from, $to])->get(['completed_at'])->groupBy(fn ($j) => $bucket($j->completed_at))->map->count();

        $points = [];
        for ($d = $monthly ? $first->copy()->startOfMonth() : $first->copy(); $d <= $last; $monthly ? $d = $d->copy()->addMonth() : $d = $d->copy()->addDay()) {
            $key = $d->format($monthly ? 'Y-m' : 'Y-m-d');
            $points[] = ['date' => $key, 'logged' => (int) ($logged[$key] ?? 0), 'completed' => (int) ($completed[$key] ?? 0)];
        }

        return ['bucket' => $monthly ? 'month' : 'day', 'points' => $points];
    }

    /** @return array{totals: array<string, int>, locations: list<array<string, mixed>>} */
    public function report(string $tz, ?int $branchId, CarbonInterface $from, CarbonInterface $to, bool $withBilling = false): array
    {
        $jobs = fn () => ServiceJob::query()->when($branchId, fn ($q) => $q->where('service_jobs.branch_id', $branchId));
        $todayStart = now($tz)->startOfDay()->utc();
        $agedBefore = ServiceJob::agedBefore($tz);

        // Right now: the same rules as the Jobs list's quick filters.
        $now = $jobs()->whereIn('status', AutoAssigner::ACTIVE_STATUSES)->toBase()
            ->selectRaw("service_location_id as id, COUNT(*) as active,
                SUM(status = 'open') as open, SUM(status = 'in_progress') as in_progress, SUM(status = 'pending') as pending, SUM(status = 'part_pending') as part_pending, SUM(status = 'cancel_requested') as cancel_requested,
                SUM(assigned_technician_id IS NULL AND status IN ('open', 'pending')) as unassigned,
                SUM(status IN ('open', 'pending') AND scheduled_at < ?) as overdue,
                SUM(created_at < ?) as aged", [$todayStart, $agedBefore])
            ->groupBy('service_location_id')->get()->keyBy(fn ($r) => (string) $r->id);

        // In the period.
        $logged = $jobs()->whereBetween('created_at', [$from, $to])->toBase()
            ->selectRaw("service_location_id as id, COUNT(*) as logged, SUM(status = 'cancelled') as cancelled, SUM(status = 'completed') as logged_completed")
            ->groupBy('service_location_id')->get()->keyBy(fn ($r) => (string) $r->id);
        $completed = $jobs()->where('status', 'completed')->whereBetween('completed_at', [$from, $to])->toBase()
            ->selectRaw('service_location_id as id, COUNT(*) as completed, AVG(TIMESTAMPDIFF(HOUR, created_at, completed_at)) as avg_hours')
            ->groupBy('service_location_id')->get()->keyBy(fn ($r) => (string) $r->id);
        $billed = $withBilling
            ? Invoice::query()->join('service_jobs', 'service_jobs.id', '=', 'invoices.job_id')
                ->when($branchId, fn ($q) => $q->where('service_jobs.branch_id', $branchId))
                ->whereBetween('invoices.generated_at', [$from, $to])->toBase()
                ->selectRaw('service_jobs.service_location_id as id, SUM(invoices.total_amount) as billed')
                ->groupBy('service_jobs.service_location_id')->pluck('billed', 'id')
            : collect();

        // Allocation: who holds the open work and who finished what, per location.
        $held = $jobs()->whereIn('status', AutoAssigner::ACTIVE_STATUSES)->whereNotNull('assigned_technician_id')->toBase()
            ->selectRaw('service_location_id as id, assigned_technician_id as tech, COUNT(*) as total')
            ->groupBy('service_location_id', 'assigned_technician_id')->get();
        $done = $jobs()->where('status', 'completed')->whereNotNull('assigned_technician_id')->whereBetween('completed_at', [$from, $to])->toBase()
            ->selectRaw('service_location_id as id, assigned_technician_id as tech, COUNT(*) as total')
            ->groupBy('service_location_id', 'assigned_technician_id')->get();

        $locations = ServiceLocation::with(['technicians' => fn ($q) => $q->where('role', Role::Technician->value)->where('status', 'active')->select('users.id')])
            ->orderBy('name')->get(['id', 'name', 'code', 'city', 'pincodes', 'is_active']);
        $staff = User::inTenant()->whereIn('id', $held->pluck('tech')->merge($done->pluck('tech'))->merge($locations->flatMap(fn ($l) => $l->technicians->pluck('id')))->unique())
            ->get(['id', 'name', 'phone', 'punch_status'])->keyBy('id');

        $row = function (?ServiceLocation $location) use ($now, $logged, $completed, $billed, $held, $done, $staff, $withBilling) {
            $key = (string) $location?->id;
            $n = $now->get($key);
            $l = $logged->get($key);
            $c = $completed->get($key);
            $inThisRow = fn ($r) => (string) $r->id === $key;
            $heldHere = $held->filter($inThisRow)->pluck('total', 'tech');
            $doneHere = $done->filter($inThisRow)->pluck('total', 'tech');
            $linked = $location ? $location->technicians->pluck('id') : collect();

            $technicians = $linked->merge($heldHere->keys())->merge($doneHere->keys())->unique()
                ->map(fn ($id) => $staff->get($id))->filter()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'phone' => $u->phone,
                    'on_duty' => $u->punch_status === 'in',
                    'linked' => $linked->contains($u->id),
                    'active_jobs' => (int) ($heldHere[$u->id] ?? 0),
                    'completed' => (int) ($doneHere[$u->id] ?? 0),
                ])
                ->sortBy([['active_jobs', 'desc'], ['completed', 'desc'], ['name', 'asc']])->values()->all();

            // Of the jobs logged in the period that weren't cancelled, how many are finished.
            $doable = (int) ($l->logged ?? 0) - (int) ($l->cancelled ?? 0);

            return [
                'id' => $location?->id,
                'name' => $location?->name,
                'code' => $location?->code,
                'city' => $location?->city,
                'pincodes' => $location?->pincodes,
                'is_active' => $location?->is_active ?? true,
                'linked_technicians' => $linked->count(),
                'on_duty' => collect($technicians)->where('linked', true)->where('on_duty', true)->count(),
                'active' => (int) ($n->active ?? 0),
                'open' => (int) ($n->open ?? 0),
                'in_progress' => (int) ($n->in_progress ?? 0),
                'pending' => (int) ($n->pending ?? 0),
                'part_pending' => (int) ($n->part_pending ?? 0),
                'cancel_requested' => (int) ($n->cancel_requested ?? 0),
                'unassigned' => (int) ($n->unassigned ?? 0),
                'overdue' => (int) ($n->overdue ?? 0),
                'aged' => (int) ($n->aged ?? 0),
                'logged' => (int) ($l->logged ?? 0),
                'completed' => (int) ($c->completed ?? 0),
                'cancelled' => (int) ($l->cancelled ?? 0),
                'completion_rate' => $doable > 0 ? (int) round(((int) $l->logged_completed / $doable) * 100) : null,
                'avg_days_to_complete' => $c && $c->avg_hours !== null ? round($c->avg_hours / 24, 1) : null,
                'technicians' => $technicians,
            ] + ($withBilling ? ['billed' => (int) ($billed[$location?->id ?? ''] ?? 0)] : []);
        };

        $rows = $locations->map($row)
            // A retired location only matters while it still has something to show.
            ->filter(fn ($r) => $r['is_active'] || $r['active'] || $r['logged'] || $r['completed']);
        $none = $row(null);
        if ($none['active'] || $none['logged'] || $none['completed']) {
            $rows->push($none);
        }
        $sorted = $rows->sortBy([['active', 'desc'], ['logged', 'desc'], ['name', 'asc']])->values();

        return [
            'totals' => [
                'active' => $sorted->sum('active'),
                'unassigned' => $sorted->sum('unassigned'),
                'overdue' => $sorted->sum('overdue'),
                'aged' => $sorted->sum('aged'),
                'logged' => $sorted->sum('logged'),
                'completed' => $sorted->sum('completed'),
                'without_technician' => $sorted->filter(fn ($r) => $r['id'] && $r['active'] > 0 && $r['linked_technicians'] === 0)->count(),
            ],
            'locations' => $sorted->all(),
        ];
    }
}
