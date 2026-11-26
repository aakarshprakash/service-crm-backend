<?php

namespace App\Services\Spares;

use App\Models\BrandSpare;
use Illuminate\Database\Eloquent\Builder;

/**
 * The list filters every SAPRE screen shares: the register, the work queues (OW billing,
 * inspection & RMA, dispatch & return), the bulk actions on "all in this list", and the Excel.
 */
class SpareQuery
{
    /** The filter names a request may carry. */
    public const KEYS = ['status', 'group', 'path', 'coverage', 'attention', 'search', 'from', 'to', 'ticket'];

    /**
     * @param  array<string, mixed>  $f  status (one or comma separated), group (pending|done), path (warranty|ow),
     *                                  coverage, attention (mismatch|company_pending), search, from, to, ticket
     */
    public static function apply(array $f, bool $withStatus = true): Builder
    {
        $statuses = $withStatus && ! empty($f['status'])
            ? array_values(array_intersect(explode(',', (string) $f['status']), BrandSpare::STATUSES))
            : [];
        $search = trim((string) ($f['search'] ?? ''));
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';

        return BrandSpare::query()
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($withStatus && ($f['group'] ?? null) === 'pending', fn ($q) => $q->whereIn('status', BrandSpare::PENDING))
            ->when($withStatus && ($f['group'] ?? null) === 'done', fn ($q) => $q->whereIn('status', BrandSpare::DONE))
            ->when(($f['path'] ?? null) === 'ow', fn ($q) => $q->where(fn ($w) => $w->where('coverage', 'out_of_warranty')->orWhere('ow_billed', true)))
            ->when(($f['path'] ?? null) === 'warranty', fn ($q) => $q->where('coverage', 'warranty')->where('ow_billed', false))
            ->when(in_array($f['coverage'] ?? null, BrandSpare::COVERAGES, true), fn ($q) => $q->where('coverage', $f['coverage']))
            ->when(($f['coverage'] ?? null) === 'none', fn ($q) => $q->whereNull('coverage'))
            ->when($withStatus && ($f['attention'] ?? null) === 'mismatch', fn ($q) => $q->where('company_pending', true)->where('status', 'pending_return'))
            ->when($withStatus && ($f['attention'] ?? null) === 'company_pending', fn ($q) => $q->where('company_pending', true))
            ->when(! empty($f['from']), fn ($q) => $q->whereDate('billing_date', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($q) => $q->whereDate('billing_date', '<=', $f['to']))
            ->when(! empty($f['ticket']), fn ($q) => $q->where('ticket_no', $f['ticket']))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('billing_doc', 'like', $like)->orWhere('material_code', 'like', $like)->orWhere('description', 'like', $like)
                ->orWhere('ticket_no', 'like', $like)->orWhere('customer_name', 'like', $like)->orWhere('inspection_no', 'like', $like)
                ->orWhere('rma_no', 'like', $like)->orWhere('lr_number', 'like', $like)->orWhere('po_number', 'like', $like)
                ->orWhere('delivery_no', 'like', $like)->orWhere('ow_bill_no', 'like', $like)));
    }

    /** Validation rules for these filters, under a prefix ("filter." for bulk, "" for a list request). */
    public static function rules(string $prefix = ''): array
    {
        return [
            "{$prefix}status" => ['nullable', 'string', 'max:300'],
            "{$prefix}group" => ['nullable', 'in:pending,done'],
            "{$prefix}path" => ['nullable', 'in:warranty,ow'],
            "{$prefix}coverage" => ['nullable', 'in:'.implode(',', [...BrandSpare::COVERAGES, 'none'])],
            "{$prefix}attention" => ['nullable', 'in:mismatch,company_pending'],
            "{$prefix}search" => ['nullable', 'string', 'max:100'],
            "{$prefix}from" => ['nullable', 'date'],
            "{$prefix}to" => ['nullable', 'date'],
            "{$prefix}ticket" => ['nullable', 'string', 'max:40'],
        ];
    }
}
