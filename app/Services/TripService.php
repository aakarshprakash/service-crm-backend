<?php

namespace App\Services;

use App\Models\JobVisit;
use App\Models\PunchLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A technician's day on the road, rebuilt from what the app already records: where they punched in and
 * out, and where each visit started and ended. Nothing extra is asked of the technician.
 *
 * Distance is the straight line between one stop and the next, in metres. Roads are never straight, so
 * the real distance driven is usually more: treat it as a floor, not an odometer reading. A leg is
 * skipped when either end has no location.
 */
class TripService
{
    /** Two readings closer than this are the same place (GPS drift at the customer's door). */
    private const SAME_PLACE_M = 60;

    /**
     * One row per technician per day with something recorded, newest day first, plus totals per technician.
     *
     * @param  Collection<int, User>  $technicians
     * @return array{days: list<array>, technicians: list<array>, totals: array}
     */
    public function summary(Tenant $tenant, Collection $technicians, string $from, string $to): array
    {
        $tz = $tenant->timezone;
        $stops = $this->stops($tenant, $technicians->pluck('id')->all(), $from, $to);
        $names = $technicians->pluck('name', 'id');

        $days = [];
        foreach ($stops as $userId => $byDate) {
            foreach ($byDate as $date => $points) {
                $days[] = ['user_id' => $userId, 'name' => $names[$userId] ?? '—', 'date' => $date] + $this->totals($points, $tz);
            }
        }
        usort($days, fn ($a, $b) => [$b['date'], $a['name']] <=> [$a['date'], $b['name']]);

        $perTech = collect($days)->groupBy('user_id')->map(fn (Collection $rows) => [
            'user_id' => $rows[0]['user_id'],
            'name' => $rows[0]['name'],
            'days' => $rows->count(),
            'visits' => $rows->sum('visits'),
            'distance_m' => $rows->sum('distance_m'),
            'travel_seconds' => $rows->sum('travel_seconds'),
            'on_site_seconds' => $rows->sum('on_site_seconds'),
            'unlocated_legs' => $rows->sum('unlocated_legs'),
        ])->sortBy('name')->values()->all();

        return [
            'days' => $days,
            'technicians' => $perTech,
            'totals' => [
                'days' => count($days),
                'visits' => array_sum(array_column($days, 'visits')),
                'distance_m' => array_sum(array_column($days, 'distance_m')),
                'travel_seconds' => array_sum(array_column($days, 'travel_seconds')),
                'on_site_seconds' => array_sum(array_column($days, 'on_site_seconds')),
            ],
        ];
    }

    /** One technician's day, stop by stop, with the leg that brought them to each stop. */
    public function day(Tenant $tenant, User $technician, string $date): array
    {
        $points = $this->stops($tenant, [$technician->id], $date, $date)[$technician->id][$date] ?? [];
        $legs = $this->legs($points);

        $stops = [];
        foreach ($points as $i => $p) {
            $stops[] = [
                'kind' => $p['kind'],
                'label' => $p['label'],
                'detail' => $p['detail'],
                'job_id' => $p['job_id'],
                'visit_status' => $p['visit_status'],
                'arrived_at' => $p['arrive']->toIso8601String(),
                'left_at' => $p['leave']?->toIso8601String(),
                'on_site_seconds' => $p['kind'] === 'visit' && $p['leave'] ? max(0, $p['arrive']->diffInSeconds($p['leave'])) : null,
                'lat' => $p['lat'],
                'lng' => $p['lng'],
                'outside_fence' => $p['outside_fence'],
                // How they got here from the stop before.
                'leg' => $i === 0 ? null : $legs[$i - 1],
            ];
        }

        return [
            'user' => ['id' => $technician->id, 'name' => $technician->name],
            'date' => $date,
            'stops' => $stops,
            'summary' => $this->totals($points, $tenant->timezone),
            'map_url' => $this->mapUrl($points),
        ];
    }

    /**
     * Everything recorded with a time for these technicians, as ordered points per technician and local date.
     *
     * @return array<int, array<string, list<array>>>
     */
    private function stops(Tenant $tenant, array $userIds, string $from, string $to): array
    {
        $tz = $tenant->timezone;
        $start = CarbonImmutable::parse($from, $tz)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $tz)->endOfDay()->utc();
        $out = [];

        $punches = PunchLog::whereIn('user_id', $userIds)->whereBetween('created_at', [$start, $end])->with('branch:id,name')->orderBy('created_at')
            ->get(['id', 'user_id', 'type', 'lat', 'lng', 'branch_id', 'within_fence', 'source', 'created_at']);
        foreach ($punches as $p) {
            $at = CarbonImmutable::parse($p->created_at);
            $out[$p->user_id][$at->timezone($tz)->toDateString()][] = [
                'kind' => $p->type === 'in' ? 'punch_in' : 'punch_out',
                'label' => $p->type === 'in' ? 'Punched in' : 'Punched out',
                'detail' => trim(($p->branch?->name ? "Near {$p->branch->name}" : '').($p->source === 'auto' ? ' · automatic' : '')) ?: null,
                'job_id' => null,
                'visit_status' => null,
                'arrive' => $at,
                'leave' => $at,
                'lat' => $p->lat !== null ? (float) $p->lat : null,
                'lng' => $p->lng !== null ? (float) $p->lng : null,
                'end_lat' => null,
                'end_lng' => null,
                'outside_fence' => $p->within_fence === false || $p->within_fence === 0,
            ];
        }

        $visits = JobVisit::whereIn('technician_id', $userIds)->whereBetween('start_time', [$start, $end])
            ->with(['job:id,crm_call_id,customer_id,service_location_id,complaint_type_id', 'job.customer:id,name,city', 'job.serviceLocation:id,name', 'job.complaintType:id,name'])
            ->orderBy('start_time')
            ->get(['id', 'job_id', 'technician_id', 'service_type', 'start_time', 'end_time', 'status', 'location_lat', 'location_lng', 'end_lat', 'end_lng']);
        foreach ($visits as $v) {
            $arrive = CarbonImmutable::parse($v->start_time);
            $place = $v->job?->serviceLocation?->name ?? $v->job?->customer?->city;
            $out[$v->technician_id][$arrive->timezone($tz)->toDateString()][] = [
                'kind' => 'visit',
                'label' => ($v->job?->customer?->name ?? 'Customer').($place ? " · {$place}" : ''),
                'detail' => trim(($v->job?->crm_call_id ?? '').($v->job?->complaintType?->name ? " · {$v->job->complaintType->name}" : '').($v->service_type !== 'on_site' ? ' · '.str_replace('_', ' ', $v->service_type) : '')),
                'job_id' => $v->job_id,
                'visit_status' => $v->status,
                'arrive' => $arrive,
                'leave' => $v->end_time ? CarbonImmutable::parse($v->end_time) : null,
                // A call handled by phone or at the office has no place of its own.
                'lat' => $v->location_lat !== null ? (float) $v->location_lat : null,
                'lng' => $v->location_lng !== null ? (float) $v->location_lng : null,
                'end_lat' => $v->end_lat !== null ? (float) $v->end_lat : null,
                'end_lng' => $v->end_lng !== null ? (float) $v->end_lng : null,
                'outside_fence' => false,
            ];
        }

        foreach ($out as &$byDate) {
            foreach ($byDate as &$points) {
                usort($points, fn ($a, $b) => $a['arrive'] <=> $b['arrive']);
            }
        }

        return $out;
    }

    /**
     * The trip from each point to the next: distance, and the time between leaving one and arriving at the other.
     *
     * @return list<array{distance_m: ?int, seconds: int}>
     */
    private function legs(array $points): array
    {
        $legs = [];
        for ($i = 1; $i < count($points); $i++) {
            $from = $points[$i - 1];
            $to = $points[$i];
            // They left from where the visit ended, when that was recorded.
            [$lat, $lng] = [$from['end_lat'] ?? $from['lat'], $from['end_lng'] ?? $from['lng']];
            $distance = $lat !== null && $lng !== null && $to['lat'] !== null && $to['lng'] !== null
                ? (int) round(Geo::distance($lat, $lng, $to['lat'], $to['lng'])) : null;
            if ($distance !== null && $distance < self::SAME_PLACE_M) {
                $distance = 0;
            }
            $left = $from['leave'] ?? $from['arrive'];
            $legs[] = ['distance_m' => $distance, 'seconds' => max(0, (int) $left->diffInSeconds($to['arrive'], false))];
        }

        return $legs;
    }

    private function totals(array $points, string $tz): array
    {
        $legs = $this->legs($points);
        $visits = array_values(array_filter($points, fn ($p) => $p['kind'] === 'visit'));
        $moving = array_filter($legs, fn ($l) => ($l['distance_m'] ?? 0) > 0);
        $in = collect($points)->firstWhere('kind', 'punch_in');
        $out = collect($points)->where('kind', 'punch_out')->last();

        return [
            'visits' => count($visits),
            'completed' => count(array_filter($visits, fn ($p) => $p['visit_status'] === 'completed')),
            'distance_m' => array_sum(array_map(fn ($l) => (int) ($l['distance_m'] ?? 0), $legs)),
            // Only the gaps in which they actually moved: waiting at the same place is not travel.
            'travel_seconds' => array_sum(array_column($moving, 'seconds')),
            'on_site_seconds' => array_sum(array_map(fn ($p) => $p['leave'] ? max(0, (int) $p['arrive']->diffInSeconds($p['leave'], false)) : 0, $visits)),
            'unlocated_legs' => count(array_filter($legs, fn ($l) => $l['distance_m'] === null)),
            'first_at' => $points ? $points[0]['arrive']->toIso8601String() : null,
            'last_at' => $points ? ($points[count($points) - 1]['leave'] ?? $points[count($points) - 1]['arrive'])->toIso8601String() : null,
            'punched_in_at' => $in ? $in['arrive']->toIso8601String() : null,
            'punched_out_at' => $out ? $out['arrive']->toIso8601String() : null,
        ];
    }

    /** The day's stops as a Google Maps route (it accepts up to about ten stops in a link). */
    private function mapUrl(array $points): ?string
    {
        $coords = [];
        foreach ($points as $p) {
            if ($p['lat'] === null || $p['lng'] === null) {
                continue;
            }
            $pair = round($p['lat'], 6).','.round($p['lng'], 6);
            if (end($coords) !== $pair) {
                $coords[] = $pair;
            }
        }
        if (count($coords) < 2) {
            return null;
        }
        if (count($coords) > 10) {
            // Keep the first and last, and spread the rest evenly.
            $step = (count($coords) - 1) / 9;
            $coords = array_map(fn ($i) => $coords[(int) round($i * $step)], range(0, 9));
        }

        return 'https://www.google.com/maps/dir/'.implode('/', $coords);
    }
}
