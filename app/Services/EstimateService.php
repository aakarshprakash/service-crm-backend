<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Estimate;
use App\Models\InventoryItem;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Gst;
use App\Support\IndianStates;
use App\Support\Money;
use App\Support\Sequence;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Estimates (quotations). Lines are a service charge or a part from the price list; prices include GST,
 * exactly as on invoices, so an approved estimate and the final bill read the same way.
 */
class EstimateService
{
    /** Printed when the company has not written its own terms (Settings can override per estimate). */
    public const DEFAULT_TERMS = "Prices include all taxes unless stated otherwise.\nParts not listed here are charged extra after your approval.\nWork starts once this estimate is approved.";

    /**
     * @param  array{customer_id: int, job_id?: int|null, customer_product_id?: int|null, branch_id?: int|null, estimate_date?: string|null,
     *               valid_until?: string|null, subject?: string|null, notes?: string|null, terms?: string|null, is_gst?: bool|null, discount?: int|null,
     *               items: list<array{type: string, item_id?: int|null, description?: string|null, quantity: float, unit_price?: int|null}>}  $data
     */
    public function create(array $data, User $by): Estimate
    {
        return DB::transaction(function () use ($data, $by) {
            $tenant = Tenant::findOrFail($by->tenant_id);
            $today = now($tenant->timezone)->toDateString();
            $date = $data['estimate_date'] ?? $today;
            $this->assertLinks($data);

            $estimate = Estimate::create([
                'estimate_number' => Sequence::formatted($tenant->id, 'estimate', (string) $tenant->setting('estimate_prefix', 'EST')),
                'customer_id' => $data['customer_id'],
                'customer_product_id' => $data['customer_product_id'] ?? null,
                'job_id' => $data['job_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? $by->branch_id ?? Branch::orderBy('id')->value('id'),
                'status' => 'draft',
                'estimate_date' => $date,
                'valid_until' => array_key_exists('valid_until', $data) ? $data['valid_until'] : \Illuminate\Support\Carbon::parse($date)->addDays((int) $tenant->setting('estimates.valid_days', 15))->toDateString(),
                'subject' => $data['subject'] ?? null,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? $tenant->setting('estimates.terms') ?? self::DEFAULT_TERMS,
                'is_gst' => $data['is_gst'] ?? ((bool) $tenant->gstin && $tenant->setting('gst.job_bills') !== 'non_gst'),
                'share_token' => Str::random(48),
                'created_by' => $by->id,
            ]);
            $this->writeLines($estimate, $tenant, $data['items'], (int) ($data['discount'] ?? 0));

            return $estimate;
        });
    }

    public function update(Estimate $estimate, array $data): Estimate
    {
        if (! $estimate->isEditable()) {
            throw ValidationException::withMessages(['status' => 'This estimate is '.$estimate->status.'. Reopen it to make changes.']);
        }

        return DB::transaction(function () use ($estimate, $data) {
            $tenant = Tenant::findOrFail($estimate->tenant_id);
            $this->assertLinks($data + ['customer_id' => $estimate->customer_id]);
            $estimate->fill(array_intersect_key($data, array_flip([
                'customer_product_id', 'job_id', 'branch_id', 'estimate_date', 'valid_until', 'subject', 'notes', 'terms', 'is_gst',
            ])))->save();
            if (isset($data['items'])) {
                $estimate->items()->delete();
                $this->writeLines($estimate, $tenant, $data['items'], (int) ($data['discount'] ?? $estimate->discount_amount));
            } else {
                $this->applyTotals($estimate, $tenant, (int) ($data['discount'] ?? $estimate->discount_amount));
            }

            return $estimate;
        });
    }

    /** sent / approved / rejected, or back to draft to change it. */
    public function setStatus(Estimate $estimate, string $status, ?string $note, User $by): Estimate
    {
        $allowed = [
            'draft' => ['sent', 'approved', 'rejected'],
            'sent' => ['draft', 'approved', 'rejected'],
            'approved' => ['draft'],
            'rejected' => ['draft'],
        ][$estimate->status] ?? [];
        if (! in_array($status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => "An estimate that is {$estimate->status} can’t be marked {$status}."]);
        }
        if ($status !== 'draft' && $estimate->total_amount <= 0) {
            throw ValidationException::withMessages(['status' => 'Add at least one line with an amount first.']);
        }
        $decided = in_array($status, ['approved', 'rejected'], true);
        $estimate->fill([
            'status' => $status,
            'sent_at' => $status === 'sent' ? now() : ($status === 'draft' ? null : ($estimate->sent_at ?? now())),
            'decided_at' => $decided ? now() : null,
            'decided_by' => $decided ? $by->id : null,
            'decision_note' => $decided ? $note : null,
        ])->save();

        return $estimate;
    }

    /** A link the customer can open without signing in: the estimate as a PDF. */
    public function shareUrl(Estimate $estimate): string
    {
        return route('estimates.public-pdf', ['token' => $estimate->share_token]);
    }

    public function pdf(Estimate $estimate): \Barryvdh\DomPDF\PDF
    {
        $tenant = Tenant::findOrFail($estimate->tenant_id);
        $estimate->loadMissing(['customer', 'branch', 'job:id,crm_call_id,complaint_type_id', 'job.complaintType:id,name',
            'customerProduct.product.brand', 'customerProduct.brand', 'customerProduct.category', 'creator:id,name']);
        $breakup = $estimate->gst_breakup ?? ['lines' => [], 'summary' => []];
        $currency = $tenant->currency ?? 'INR';
        $logo = $tenant->logo_path && Storage::disk('private')->exists($tenant->logo_path)
            ? 'data:image/png;base64,'.base64_encode(Storage::disk('private')->get($tenant->logo_path)) : null;
        $companyState = IndianStates::code($tenant->gstin) ?? IndianStates::code($tenant->state);
        $customerState = IndianStates::code($estimate->customer?->gstin) ?? IndianStates::code($estimate->customer?->state);

        return Pdf::loadView('pdf.estimate', [
            'estimate' => $estimate,
            'tenant' => $tenant,
            'logo' => $logo,
            'lines' => $breakup['lines'],
            'summary' => $estimate->is_gst ? $breakup['summary'] : [],
            'gross' => array_sum(array_column($breakup['lines'], 'gross')),
            'discount' => $estimate->discount_amount,
            'interState' => $estimate->igst_amount !== 0,
            'money' => fn (int $v) => Money::format($v, $currency),
            'qty' => fn ($q) => rtrim(rtrim(number_format((float) $q, 3), '0'), '.'),
            'words' => Money::inWords($estimate->total_amount, $currency),
            'companyState' => $companyState ? IndianStates::name($companyState).' ('.$companyState.')' : $tenant->state,
            'customerState' => $customerState ? IndianStates::name($customerState).' ('.$customerState.')' : $estimate->customer?->state,
        ])->setPaper('a4');
    }

    private function assertLinks(array $data): void
    {
        $customerId = $data['customer_id'];
        if (! empty($data['job_id']) && ! ServiceJob::where('customer_id', $customerId)->whereKey($data['job_id'])->exists()) {
            throw ValidationException::withMessages(['job_id' => 'This job belongs to another customer.']);
        }
        if (! empty($data['customer_product_id']) && ! CustomerProduct::where('customer_id', $customerId)->whereKey($data['customer_product_id'])->exists()) {
            throw ValidationException::withMessages(['customer_product_id' => 'This product belongs to another customer.']);
        }
    }

    private function writeLines(Estimate $estimate, Tenant $tenant, array $items, int $discount): void
    {
        foreach (array_values($items) as $i => $line) {
            $qty = round((float) $line['quantity'], 3);
            $item = $line['type'] === 'part' && ! empty($line['item_id']) ? InventoryItem::findOrFail($line['item_id']) : null;
            $price = (int) ($line['unit_price'] ?? $item?->unit_price ?? 0);
            $description = trim((string) ($line['description'] ?? '')) ?: ($item ? "{$item->name} ({$item->code})" : '');
            if ($description === '') {
                throw ValidationException::withMessages(["items.$i.description" => $line['type'] === 'part' ? 'Choose the part, or describe it.' : 'Describe the service.']);
            }
            $estimate->items()->create([
                'type' => $line['type'], 'item_id' => $item?->id, 'description' => mb_substr($description, 0, 300),
                'quantity' => $qty, 'unit_price' => $price, 'total' => (int) round($qty * $price), 'position' => $i,
            ]);
        }
        $this->applyTotals($estimate, $tenant, $discount);
    }

    /** Totals and the GST breakup, snapshotted on the estimate like an invoice's. */
    private function applyTotals(Estimate $estimate, Tenant $tenant, int $discount): void
    {
        $items = $estimate->items()->with('item')->get();
        $service = (int) $items->where('type', 'service')->sum('total');
        $parts = (int) $items->where('type', 'part')->sum('total');
        if ($discount > $service + $parts) {
            throw ValidationException::withMessages(['discount' => 'Discount can’t be more than the estimate total.']);
        }
        if ($estimate->is_gst && ! $tenant->gstin) {
            throw ValidationException::withMessages(['is_gst' => 'Add your GSTIN under Settings → Company to give GST estimates.']);
        }

        $sac = (string) $tenant->setting('gst.service_sac', '998719');
        $serviceRate = (float) $tenant->setting('gst.service_rate', 18);
        $partRate = (float) $tenant->setting('gst.part_rate', 18);
        $gst = (bool) $estimate->is_gst;
        $lines = $items->map(fn ($l) => [
            'type' => $l->type,
            'description' => $l->description,
            'qty' => (float) $l->quantity,
            'gross' => (int) $l->total,
            'uom' => $l->item?->unit_of_measure,
            'hsn' => $l->type === 'part' ? $l->item?->hsn_code : $sac,
            // A plain estimate shows no tax: every line is taken at 0%.
            'gst_rate' => ! $gst ? 0 : ($l->type === 'part' ? ($l->item?->gst_rate !== null ? (float) $l->item->gst_rate : $partRate) : $serviceRate),
        ])->all();

        $customer = Customer::withTrashed()->find($estimate->customer_id);
        $ours = IndianStates::code($tenant->gstin) ?? IndianStates::code($tenant->state);
        $theirs = IndianStates::code($customer?->gstin) ?? IndianStates::code($customer?->state);
        $breakup = Gst::breakup($lines, $discount, $gst && $ours && $theirs && $ours !== $theirs);

        $estimate->forceFill([
            'total_service_charge' => $service,
            'total_spare_charge' => $parts,
            'discount_amount' => $discount,
            'total_amount' => $breakup['totals']['total'],
            'taxable_amount' => $breakup['totals']['taxable'],
            'cgst_amount' => $breakup['totals']['cgst'],
            'sgst_amount' => $breakup['totals']['sgst'],
            'igst_amount' => $breakup['totals']['igst'],
            'place_of_supply' => $gst ? IndianStates::name($theirs ?? $ours) : null,
            'customer_gstin' => $gst ? ($customer?->gstin ?: null) : null,
            'gst_breakup' => ['lines' => $breakup['lines'], 'summary' => $breakup['summary']],
        ])->save();
    }
}
