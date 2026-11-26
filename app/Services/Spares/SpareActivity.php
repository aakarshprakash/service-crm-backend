<?php

namespace App\Services\Spares;

use App\Models\AuditLog;
use App\Models\BrandSpare;
use App\Models\SpareImport;
use App\Support\TenantContext;
use Illuminate\Support\Collection;

/**
 * What happened to spare parts, in words: one part's history (the Activity tab) and the latest
 * changes across all parts (the dashboard). A bulk action writes one log per part; the feed shows
 * it once, with the number of parts.
 */
class SpareActivity
{
    /** Changed field → what the change means, in the order a reader cares about. */
    private const MEANING = [
        'return_received_on' => 'Received by Lloyd',
        'replacement_doc' => 'Replacement part recorded',
        'replacement_on' => 'Replacement part recorded',
        'lr_number' => 'Sent by courier',
        'lr_date' => 'Sent by courier',
        'ow_paid_on' => 'OW payment recorded',
        'ow_billed_on' => 'Billed as OW by Lloyd',
        'ow_billed' => 'Billed as OW by Lloyd',
        'ow_stage' => 'OW stage changed',
        'inspection_no' => 'Inspection number added',
        'rma_no' => 'RMA / challan added',
        'return_type' => 'Marked as fresh return',
        'coverage' => 'Warranty / OW changed',
        'company_pending' => "Lloyd's pending list updated",
    ];

    /** One part's history, newest first. */
    public function forSpare(BrandSpare $spare): array
    {
        $logs = AuditLog::with('user:id,name')
            ->where('tenant_id', app(TenantContext::class)->id())
            ->where('model', 'BrandSpare')->where('model_id', $spare->id)
            ->latest('id')->limit(100)->get();

        $items = $logs->map(fn (AuditLog $log) => [
            'at' => $log->created_at?->toIso8601String(),
            'user' => $log->user?->name,
            'title' => $this->title($log),
            'details' => $this->details($log),
        ])->all();

        // Lines brought in by an upload are inserted in bulk, without a "created" log.
        if (! $logs->contains('action', 'created')) {
            $items[] = ['at' => $spare->created_at?->toIso8601String(), 'user' => $spare->creator?->name, 'title' => 'Added from a Lloyd bill upload', 'details' => []];
        }

        return $items;
    }

    /** The latest changes across all parts, a bulk action shown once. */
    public function recent(int $limit = 8): array
    {
        $tenantId = app(TenantContext::class)->id();
        $logs = AuditLog::with('user:id,name')
            ->where('tenant_id', $tenantId)->where('model', 'BrandSpare')
            ->latest('id')->limit(400)->get();

        $groups = new Collection;
        foreach ($logs as $log) {
            $title = $this->title($log);
            $last = $groups->last();
            // Same person, same change, within a couple of minutes: one bulk action.
            if ($last && $last['user_id'] === $log->user_id && $last['title'] === $title
                && abs($last['time'] - $log->created_at->getTimestamp()) < 180) {
                $last['count']++;
                $groups->put($groups->keys()->last(), $last);

                continue;
            }
            if ($groups->count() >= $limit) {
                break;
            }
            $groups->push(['user_id' => $log->user_id, 'user' => $log->user?->name, 'title' => $title, 'count' => 1,
                'time' => $log->created_at->getTimestamp(), 'kind' => $this->kind($log)]);
        }

        $uploads = SpareImport::with('user:id,name')->latest('id')->limit($limit)->get()->map(fn (SpareImport $i) => [
            'user' => $i->user?->name, 'count' => $i->added + $i->updated, 'time' => $i->created_at->getTimestamp(), 'kind' => 'upload',
            'title' => $i->kind === 'pending' ? "Lloyd's pending list uploaded" : 'Excel uploaded: '.(implode(', ', array_filter([
                $i->added ? number_format($i->added).' parts added' : null,
                $i->updated ? number_format($i->updated).' updated' : null,
            ])) ?: 'nothing new'),
        ]);

        return $groups->concat($uploads)->sortByDesc('time')->take($limit)->map(fn ($g) => [
            'at' => date(DATE_ATOM, $g['time']), 'user' => $g['user'], 'title' => $g['title'], 'parts' => $g['count'], 'kind' => $g['kind'],
        ])->values()->all();
    }

    private function title(AuditLog $log): string
    {
        if ($log->action === 'created') {
            return 'Part added by hand';
        }
        if ($log->action === 'deleted') {
            return 'Part line deleted';
        }
        $changed = array_keys($log->changes['new'] ?? []);
        foreach (self::MEANING as $field => $meaning) {
            if (in_array($field, $changed, true)) {
                return $meaning;
            }
        }

        return 'Details edited';
    }

    /** The kind of change, for the icon next to it. */
    private function kind(AuditLog $log): string
    {
        $title = $this->title($log);

        return match (true) {
            str_contains($title, 'courier'), str_contains($title, 'Received') => 'dispatch',
            str_contains($title, 'Inspection'), str_contains($title, 'RMA') => 'inspection',
            str_contains($title, 'OW') => 'billing',
            default => 'edit',
        };
    }

    /** "Inspection number: – → 3925016159W", for the fields that changed. */
    private function details(AuditLog $log): array
    {
        $old = $log->changes['old'] ?? [];
        $new = $log->changes['new'] ?? [];
        $out = [];
        foreach ($new as $field => $value) {
            if (in_array($field, ['status', 'updated_at', 'created_at', 'import_key'], true)) {
                continue;
            }
            $out[] = ['field' => $field, 'from' => $old[$field] ?? null, 'to' => $value];
        }

        return $out;
    }
}
