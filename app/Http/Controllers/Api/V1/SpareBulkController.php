<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BrandSpare;
use App\Services\Spares\SpareQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * One SAPRE step on many parts at once: the ticked ids, or every part a list filter matches.
 * The brand inspects, collects and bills in batches (one inspection, one LR, one OW bill covers
 * dozens of parts), so this is how most of the work gets done.
 *
 * Each step applies only to parts at the right point of the flow; the others are skipped and
 * counted, so a mixed selection never sends an OW part to Lloyd or bills a part already paid.
 */
class SpareBulkController extends Controller
{
    /** Parts changed in one bulk step at most. */
    private const LIMIT = 2000;

    /** The step → the statuses a part may be at for the step to apply. */
    private const ALLOWED = [
        'inspect' => ['unsorted', 'pending_inspection', 'pending_dispatch'],
        'rma' => ['unsorted', 'pending_inspection', 'pending_dispatch'],
        'fresh' => ['unsorted', 'pending_inspection', 'pending_dispatch'],
        'send' => ['unsorted', 'pending_inspection', 'pending_dispatch'],
        'received' => ['pending_return'],
        'ow_billed' => ['unsorted', 'pending_inspection', 'pending_dispatch', 'pending_return', 'ow_billing_pending'],
        'ow_paid' => ['ow_billing_pending', 'payment_pending'],
    ];

    private const ACTIONS = ['coverage', 'inspect', 'rma', 'fresh', 'send', 'received', 'ow_billed', 'ow_paid', 'company_cleared', 'delete'];

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(self::ACTIONS)],
            'ids' => ['required_without:filter', 'array', 'max:'.self::LIMIT],
            'ids.*' => ['integer'],
            'filter' => ['required_without:ids', 'array'],
            'filter.all' => ['nullable', 'boolean'],
            ...SpareQuery::rules('filter.'),
            'coverage' => ['required_if:action,coverage', 'nullable', Rule::in(BrandSpare::COVERAGES)],
            'inspection_no' => ['required_if:action,inspect', 'nullable', 'string', 'max:120'],
            'inspection_on' => ['nullable', 'date'],
            'rma_no' => ['required_if:action,rma', 'nullable', 'string', 'max:120'],
            'rma_on' => ['nullable', 'date'],
            'lr_date' => ['required_if:action,send', 'nullable', 'date'],
            'lr_number' => ['nullable', 'string', 'max:60'],
            'courier' => ['required_if:action,send', 'nullable', 'string', 'max:60'],
            'return_received_on' => ['required_if:action,received', 'nullable', 'date'],
            'ow_billed_on' => ['required_if:action,ow_billed', 'nullable', 'date'],
            'ow_bill_no' => ['nullable', 'string', 'max:30'],
            'ow_paid_on' => ['required_if:action,ow_paid', 'nullable', 'date'],
            'ow_payment_ref' => ['nullable', 'string', 'max:60'],
        ], [
            'coverage.required_if' => 'Choose warranty, out of warranty or stock.',
            'inspection_no.required_if' => 'Enter the inspection number.',
            'rma_no.required_if' => 'Enter the RMA or challan number.',
            'lr_date.required_if' => 'Enter the date the parts were handed to the courier.',
            'courier.required_if' => 'Enter the courier.',
            'return_received_on.required_if' => 'Enter the date Lloyd received the parts.',
            'ow_billed_on.required_if' => 'Enter the date Lloyd billed it as OW.',
            'ow_paid_on.required_if' => 'Enter the date it was paid.',
        ]);

        $spares = $this->target($data);
        if ($data['action'] === 'delete') {
            DB::transaction(fn () => $spares->each->delete());

            return $this->ok(['updated' => $spares->count(), 'skipped' => 0], $spares->count() === 1 ? 'Part line deleted.' : "{$spares->count()} part lines deleted.");
        }

        $updated = 0;
        $skipped = 0;
        DB::transaction(function () use ($spares, $data, &$updated, &$skipped) {
            foreach ($spares as $spare) {
                $allowed = self::ALLOWED[$data['action']] ?? null;
                if ($allowed !== null && ! in_array($spare->status, $allowed, true)) {
                    $skipped++;

                    continue;
                }
                $spare->fill($this->changes($data, $spare))->save();
                $updated++;
            }
        });

        $message = ($updated === 1 ? '1 part updated.' : "{$updated} parts updated.")
            .($skipped ? ' '.($skipped === 1 ? '1 part was' : "{$skipped} parts were").' skipped because this step does not apply to them.' : '');

        return $this->ok(['updated' => $updated, 'skipped' => $skipped], $message);
    }

    /** The parts to change: the ticked ids, or every part a known filter (or "all", on purpose) matches. */
    private function target(array $data)
    {
        if (isset($data['ids'])) {
            $query = BrandSpare::whereIn('id', $data['ids']);
        } else {
            $filter = array_filter($data['filter'] ?? [], fn ($v) => $v !== null && $v !== '');
            if (! array_intersect_key($filter, array_flip(['all', ...SpareQuery::KEYS]))) {
                throw ValidationException::withMessages(['filter' => 'Choose the parts to change first.']);
            }
            $query = SpareQuery::apply($filter);
        }

        $spares = $query->limit(self::LIMIT + 1)->get();
        if ($spares->count() > self::LIMIT) {
            throw ValidationException::withMessages([isset($data['ids']) ? 'ids' : 'filter' => 'That is more than '.number_format(self::LIMIT).' parts. Narrow the list with a status, date or search first.']);
        }

        return $spares;
    }

    /** The fields a step writes on one part. */
    private function changes(array $data, BrandSpare $spare): array
    {
        $optional = fn (string $k) => ($data[$k] ?? '') !== '' ? [$k => $data[$k]] : [];
        $today = now()->toDateString();
        // A warranty step on a part nobody sorted yet makes it a warranty part.
        $warranty = $spare->coverage === null ? ['coverage' => 'warranty'] : [];

        return match ($data['action']) {
            'coverage' => ['coverage' => $data['coverage'], 'ow_stage' => $data['coverage'] === 'out_of_warranty' ? ($spare->ow_stage ?? 'marked') : ($spare->ow_billed ? $spare->ow_stage : null)],
            'inspect' => [...$warranty, 'inspection_no' => $data['inspection_no'], 'inspection_on' => $data['inspection_on'] ?? $today, ...$optional('rma_no')],
            'rma' => [...$warranty, 'rma_no' => $data['rma_no'], 'rma_on' => $data['rma_on'] ?? $today],
            'fresh' => [...$warranty, 'return_type' => 'fresh'],
            'send' => [...$warranty, 'lr_date' => $data['lr_date'], 'courier' => $data['courier'], ...$optional('lr_number'), ...$optional('rma_no')],
            'received' => ['return_received_on' => $data['return_received_on'], 'company_pending' => false],
            // Lloyd billed it: an unsorted part is out of warranty; a warranty part is charged to us.
            'ow_billed' => [
                ...($spare->coverage === null ? ['coverage' => 'out_of_warranty'] : []),
                ...($spare->coverage === 'warranty' ? ['ow_billed' => true] : []),
                'ow_stage' => 'billed', 'ow_billed_on' => $data['ow_billed_on'], ...$optional('ow_bill_no'),
            ],
            'ow_paid' => ['ow_stage' => 'paid', 'ow_paid_on' => $data['ow_paid_on'], ...$optional('ow_payment_ref')],
            'company_cleared' => ['company_pending' => false],
        };
    }
}
