<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Models\ActionTakenOption;
use App\Models\CustomerProduct;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\JobImage;
use App\Models\JobInventoryUsage;
use App\Models\JobVoiceNote;
use App\Models\JobVisit;
use App\Models\ProductCategory;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ImageOptimizer;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Technician service execution (§5.6, §12.2).
 */
class VisitService
{
    public function __construct(
        private JobService $jobs,
        private InventoryService $inventory,
        private InvoiceService $invoices,
        private PaymentService $payments,
        private NotificationService $notifications,
    ) {}

    public function start(ServiceJob $job, User $technician, array $data): JobVisit
    {
        return DB::transaction(function () use ($job, $technician, $data) {
            $job = ServiceJob::whereKey($job->id)->lockForUpdate()->firstOrFail();

            if ($job->assigned_technician_id !== $technician->id) {
                throw ValidationException::withMessages(['job' => 'This job is not assigned to you.']);
            }
            if (! in_array($job->status, [JobStatus::Open, JobStatus::Pending, JobStatus::PartPending], true)) {
                throw ValidationException::withMessages(['job' => $job->status === JobStatus::InProgress
                    ? 'A visit is already in progress for this job.'
                    : 'This job is '.$job->status->value.'.']);
            }
            if ($job->needs_resume_approval) {
                throw ValidationException::withMessages(['job' => 'This job was left '.str_replace('_', ' ', $job->status->value).'. Ask the office to approve it before the next visit.']);
            }
            $tenant = Tenant::findOrFail($job->tenant_id);
            if ($tenant->setting('jobs.require_punch_in', true) && User::whereKey($technician->id)->value('punch_status') !== 'in') {
                throw ValidationException::withMessages(['punch' => 'Punch in before you start a service.']);
            }
            if ($data['service_type'] === 'on_site' && (! isset($data['lat'], $data['lng']))) {
                // FR-6.2: location must be available before an on-site visit starts.
                throw ValidationException::withMessages(['location' => 'Turn on location services to start an on-site visit.']);
            }
            $open = JobVisit::where('technician_id', $technician->id)->where('status', 'in_progress')->with('job:id,crm_call_id')->first();
            if ($open) {
                throw ValidationException::withMessages(['job' => "Finish your active visit on job {$open->job?->crm_call_id} first."]);
            }

            $tz = $tenant->timezone ?? config('app.timezone');
            $visit = JobVisit::create([
                'job_id' => $job->id,
                'technician_id' => $technician->id,
                'visit_date' => now($tz)->toDateString(),
                'service_type' => $data['service_type'],
                'start_time' => now(),
                'status' => 'in_progress',
                'location_lat' => $data['lat'] ?? null,
                'location_lng' => $data['lng'] ?? null,
            ]);

            $job->service_type = $data['service_type'];
            // The go-ahead covers this one visit: left pending again, the next one needs another.
            $job->resume_approved_at = null;
            $job->resume_approved_by = null;
            $job->resume_requested_at = null;
            $job->resume_request_note = null;
            $this->jobs->transition($job, JobStatus::InProgress, $technician->id, 'Service started ('.str_replace('_', ' ', $data['service_type']).')');

            return $visit;
        });
    }

    /** Call-tab edits while the visit is open (FR-6.5). */
    public function updateCallDetails(JobVisit $visit, array $data): JobVisit
    {
        $this->ensureOpen($visit);

        return DB::transaction(function () use ($visit, $data) {
            $this->applyCallDetails($visit->job, $data);
            $visit->fill(array_intersect_key($data, array_flip(['service_summary', 'service_type'])))->save();

            return $visit;
        });
    }

    /** "Check details" before the visit starts: the same call fields, on an open or pending job assigned to the technician. */
    public function prepareJob(ServiceJob $job, User $technician, array $data): ServiceJob
    {
        if ($job->assigned_technician_id !== $technician->id) {
            throw ValidationException::withMessages(['job' => 'This job is not assigned to you.']);
        }
        if (! in_array($job->status, [JobStatus::Open, JobStatus::Pending, JobStatus::PartPending], true)) {
            throw ValidationException::withMessages(['job' => 'Only open or pending jobs can be updated before a visit.']);
        }
        if ($job->needs_resume_approval) {
            throw ValidationException::withMessages(['job' => 'Ask the office to approve this job before the next visit.']);
        }

        return DB::transaction(fn () => $this->applyCallDetails($job, $data));
    }

    /**
     * The complaint is the office's record of what the customer reported: the technician sees it but doesn't
     * change it (their findings go in the service summary and the job's updates). Older app versions still
     * send those fields back, so they're dropped rather than refused.
     */
    private function applyCallDetails(ServiceJob $job, array $data): ServiceJob
    {
        $job->fill(array_intersect_key($data, array_flip(['customer_satisfaction_code'])))->save();
        $this->applyProductDetails($job, $data);

        return $job;
    }

    /**
     * The unit in front of the technician. Brand, product type, model and the job's product category are locked
     * once the office has entered them; the technician fills in any that are missing, and corrects the rest
     * (serial numbers, warranty, purchase date, dealer).
     */
    private function applyProductDetails(ServiceJob $job, array $data): void
    {
        $details = array_intersect_key($data, array_flip(['serial_no', 'outdoor_serial_no', 'purchase_date', 'warranty_type', 'warranty_expiry', 'dealer_id']));
        $identity = array_filter(array_intersect_key($data, array_flip(['model_no', 'category_id', 'brand_id'])), fn ($v) => trim((string) $v) !== '');
        $product = $job->customerProduct;

        if (! $product) {
            if (! $identity && ! array_filter($details)) {
                return;
            }
            $product = CustomerProduct::create(['customer_id' => $job->customer_id] + $details + $identity);
            $job->forceFill(['customer_product_id' => $product->id])->save();
            $job->setRelation('customerProduct', $product);
        } else {
            $current = [
                'model_no' => $product->model_no ?: $product->product?->model_name,
                'category_id' => $product->category_id ?: $product->product?->category_id,
                'brand_id' => $product->brand_id ?: $product->product?->brand_id,
            ];
            $names = ['model_no' => 'Model', 'category_id' => 'Product type', 'brand_id' => 'Brand'];
            foreach ($identity as $key => $value) {
                if (! $current[$key]) {
                    continue; // missing: the technician may add it
                }
                $same = $key === 'model_no' ? strcasecmp(trim((string) $value), (string) $current[$key]) === 0 : (int) $value === (int) $current[$key];
                if (! $same) {
                    throw ValidationException::withMessages([$key => "{$names[$key]} was entered by the office. Ask them to change it."]);
                }
                unset($identity[$key]); // sent back unchanged
            }
            // Filling a gap on a catalogue unit: keep what the link was supplying alongside it.
            if ($product->product_id && $identity) {
                $product->fill(array_filter($current));
                $product->product_id = null;
            }
            $product->fill($details + $identity)->save();
        }

        // The job's product category comes from the product type only while the office hasn't written one.
        if (isset($identity['category_id']) && ! $job->product_category) {
            $job->forceFill(['product_category' => ProductCategory::whereKey($identity['category_id'])->value('name')])->save();
        }
    }

    /** Asking to cancel without starting a visit, e.g. the customer called it off. The office decides. */
    public function requestCancel(ServiceJob $job, User $technician, string $reason): ServiceJob
    {
        return DB::transaction(function () use ($job, $technician, $reason) {
            $job = ServiceJob::whereKey($job->id)->lockForUpdate()->firstOrFail();
            if ($job->assigned_technician_id !== $technician->id) {
                throw ValidationException::withMessages(['job' => 'This job is not assigned to you.']);
            }
            if (! in_array($job->status, [JobStatus::Open, JobStatus::Pending, JobStatus::PartPending], true)) {
                throw ValidationException::withMessages(['job' => $job->status === JobStatus::InProgress
                    ? 'Close the visit in progress and choose “Cancel job” there.'
                    : 'This job is '.str_replace('_', ' ', $job->status->value).'.']);
            }
            $this->jobs->requestCancel($job, $reason, $technician->id);

            return $job;
        });
    }

    public function addInventory(JobVisit $visit, User $technician, array $data): JobInventoryUsage
    {
        $this->ensureOpen($visit);
        $item = InventoryItem::where('is_active', true)->findOrFail($data['item_id']);
        $quantity = (float) $data['quantity'];
        if ($item->unit_of_measure === 'nos' && floor($quantity) != $quantity) {
            throw ValidationException::withMessages(['quantity' => 'Whole numbers only for items counted in nos.']);
        }
        // Parts allocated to the technician come first, unless the app asks for the branch's stock.
        $source = $data['source'] ?? ($this->inventory->technicianHolds($technician->id, $item->id) >= $quantity ? 'technician' : 'branch');
        if ($source === 'technician') {
            return $this->inventory->consumeFromTechnician($visit, $item, $technician->id, $quantity, $data['unit_price'] ?? null, $technician->id);
        }
        $branchId = $data['branch_id'] ?? $technician->branch_id ?? $visit->job->branch_id;
        if (! $branchId) {
            throw ValidationException::withMessages(['branch_id' => 'No branch is linked to you or this job to take stock from.']);
        }

        return $this->inventory->consumeForVisit($visit, $item, (int) $branchId, $quantity, $data['unit_price'] ?? null, $technician->id);
    }

    public function removeInventory(JobVisit $visit, JobInventoryUsage $usage, User $user): void
    {
        $this->ensureOpen($visit);
        if ($usage->job_visit_id !== $visit->id) {
            abort(404);
        }
        $this->inventory->returnUsage($usage, $visit->technician_id, $user->id);
    }

    public function uploadImage(JobVisit $visit, string $type, UploadedFile $file, User $user): JobImage
    {
        $this->ensureOpen($visit);
        $dir = "tenants/{$visit->tenant_id}/jobs/{$visit->job_id}";
        $optimized = ImageOptimizer::shrink($file->getRealPath());
        if ($optimized) {
            [$bytes, $ext] = $optimized;
            $path = $dir.'/'.Str::uuid().'.'.$ext;
            Storage::disk('private')->put($path, $bytes);
            $size = strlen($bytes);
        } else {
            $path = $file->storeAs($dir, Str::uuid().'.'.($file->guessExtension() ?: 'jpg'), 'private');
            $size = $file->getSize();
        }

        return JobImage::create([
            'job_id' => $visit->job_id,
            'job_visit_id' => $visit->id,
            'type' => $type,
            'file_path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'size' => $size,
            'uploaded_by' => $user->id,
        ]);
    }

    /** Customer sign-off; signing again replaces the earlier signature. */
    public function sign(JobVisit $visit, UploadedFile $file, string $signerName, User $user): JobVisit
    {
        $this->ensureOpen($visit);
        $previous = JobImage::where('job_visit_id', $visit->id)->where('type', 'signature')->get();
        $path = $file->storeAs("tenants/{$visit->tenant_id}/jobs/{$visit->job_id}", Str::uuid().'.png', 'private');

        DB::transaction(function () use ($visit, $file, $path, $signerName, $user, $previous) {
            JobImage::create([
                'job_id' => $visit->job_id,
                'job_visit_id' => $visit->id,
                'type' => 'signature',
                'file_path' => $path,
                'original_name' => 'signature.png',
                'size' => $file->getSize(),
                'uploaded_by' => $user->id,
            ]);
            JobImage::whereKey($previous->modelKeys())->delete();
            $visit->update(['signer_name' => $signerName, 'signed_at' => now()]);
        });
        foreach ($previous as $old) {
            Storage::disk('private')->delete($old->file_path);
        }

        return $visit->fresh('images');
    }

    public function addVoiceNote(JobVisit $visit, UploadedFile $file, int $duration, User $user): JobVoiceNote
    {
        $this->ensureOpen($visit);
        $ext = match ($file->getMimeType()) {
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/webm', 'video/webm' => 'webm',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/3gpp', 'video/3gpp' => '3gp',
            'audio/aac' => 'aac',
            default => 'm4a',
        };
        $path = $file->storeAs("tenants/{$visit->tenant_id}/jobs/{$visit->job_id}/voice", Str::uuid().'.'.$ext, 'private');
        // m4a / 3gp / webm recordings are often detected as video/*; they're audio-only.
        $mime = preg_replace('#^video/#', 'audio/', (string) $file->getMimeType());

        return JobVoiceNote::create([
            'job_id' => $visit->job_id,
            'job_visit_id' => $visit->id,
            'file_path' => $path,
            'mime' => $mime,
            'duration_seconds' => max(0, min($duration, 1800)),
            'size' => $file->getSize(),
            'uploaded_by' => $user->id,
        ]);
    }

    public function deleteVoiceNote(JobVoiceNote $note): void
    {
        $this->ensureOpen($note->visit);
        Storage::disk('private')->delete($note->file_path);
        $note->delete();
    }

    public function deleteImage(JobImage $image): void
    {
        $this->ensureOpen($image->visit);
        Storage::disk('private')->delete($image->file_path);
        $image->delete();
    }

    /**
     * Close the visit (FR-6.6 / FR-6.7 / FR-6.9): status, action taken, charges and
     * the payment the service agent collected on the spot.
     */
    public function complete(JobVisit $visit, User $technician, array $data): array
    {
        $status = $data['status'];
        if ($status === 'completed' && empty($data['action_taken_id'])) {
            // FR-6.7: enforced server-side, never optional for a completed visit.
            throw ValidationException::withMessages(['action_taken_id' => 'Select the action taken to mark the visit completed.']);
        }
        if (! empty($data['action_taken_id']) && ! ActionTakenOption::where('is_active', true)->whereKey($data['action_taken_id'])->exists()) {
            throw ValidationException::withMessages(['action_taken_id' => 'Select a valid action taken.']);
        }

        $result = DB::transaction(function () use ($visit, $technician, $data, $status) {
            $visit = JobVisit::whereKey($visit->id)->lockForUpdate()->firstOrFail();
            $this->ensureOpen($visit);
            $job = ServiceJob::whereKey($visit->job_id)->lockForUpdate()->firstOrFail();

            $usageTotal = (int) $visit->inventoryUsage()->sum('total_price');
            $labour = (int) ($data['labour_charge'] ?? 0);
            $spare = array_key_exists('spare_charge', $data) && $data['spare_charge'] !== null ? (int) $data['spare_charge'] : $usageTotal;
            $method = $data['payment_method'] ?? null;
            $collect = (int) ($data['amount_collected'] ?? 0);

            // Nothing is billed while the office decides. Parts go back first, so stock stays right if it's approved.
            if ($status === 'cancelled') {
                if ($visit->inventoryUsage()->exists()) {
                    throw ValidationException::withMessages(['status' => 'Remove the parts from this visit before asking to cancel the job.']);
                }
                if ($labour + $spare > 0 || $collect > 0) {
                    throw ValidationException::withMessages(['status' => 'A job being cancelled can’t have charges or a payment on this visit.']);
                }
            }

            if ($labour + $spare > 0 && ! $method) {
                throw ValidationException::withMessages(['payment_method' => 'Select how the customer is paying.']);
            }
            if (in_array($method, ['credit', 'online'], true)) {
                $collect = 0;
            }

            if ($status === 'completed' && Tenant::findOrFail($visit->tenant_id)->setting('jobs.require_signature', false)
                && ! JobImage::where('job_visit_id', $visit->id)->where('type', 'signature')->exists()) {
                throw ValidationException::withMessages(['signature' => 'Take the customer\'s signature before completing the visit.']);
            }

            $end = now();
            $visit->fill([
                'status' => $status,
                'end_time' => $end,
                'duration_seconds' => (int) $visit->start_time->diffInSeconds($end),
                'action_taken_id' => $data['action_taken_id'] ?? null,
                'service_summary' => $data['service_summary'] ?? $visit->service_summary,
                'assisted_staff_id' => $data['assisted_staff_id'] ?? null,
                'labour_charge' => $labour,
                'spare_charge' => $spare,
                'total_charge' => $labour + $spare,
                'payment_method' => $method,
                'amount_collected' => $collect,
                'end_lat' => $data['lat'] ?? null,
                'end_lng' => $data['lng'] ?? null,
            ])->save();

            // The customer usually gives this code once the work is done.
            if (! empty($data['customer_satisfaction_code'])) {
                $job->customer_satisfaction_code = $data['customer_satisfaction_code'];
            }

            $jobStatus = $status === 'cancelled' ? JobStatus::CancelRequested : JobStatus::from($status);
            if ($jobStatus === JobStatus::PartPending) {
                // What the technician wrote is what the office needs to order.
                $job->part_pending_note = mb_substr((string) $visit->service_summary, 0, 500);
            }
            if ($jobStatus === JobStatus::CancelRequested) {
                $this->jobs->requestCancel($job, (string) $visit->service_summary, $technician->id);
            } else {
                $this->jobs->transition($job, $jobStatus, $technician->id, 'Visit closed as '.str_replace('_', ' ', $status).($visit->actionTaken ? ' – '.$visit->actionTaken->name : ''));
            }

            // The technician picks the bill type when the bill is first made. Once it exists it may
            // already be with the customer, so changing it is for accounts (invoices.change_bill_type).
            $newBill = ! Invoice::where('job_id', $job->id)->exists();
            $invoice = $this->invoices->syncForJob($job);
            if ($invoice && $newBill && $status !== 'cancelled' && isset($data['is_gst'])) {
                $invoice = $this->invoices->changeBillType($invoice, (bool) $data['is_gst']);
            }

            $payment = null;
            if ($collect > 0) {
                if (! $invoice) {
                    throw ValidationException::withMessages(['amount_collected' => 'Nothing to collect: total charge is zero.']);
                }
                if (! in_array($method, PaymentMethod::offline(), true)) {
                    throw ValidationException::withMessages(['payment_method' => 'Select cash, UPI, cheque or bank transfer for the amount collected.']);
                }
                // Collections made after the day's cash close roll into the next close.
                $payment = $this->payments->recordOffline($invoice, $collect, $method, [
                    'job_visit_id' => $visit->id,
                    'reference_no' => $data['payment_reference'] ?? null,
                    'collected_by' => $technician->id,
                    'remarks' => 'Collected at visit',
                ]);
                $invoice->refresh();
            }

            return ['visit' => $visit->fresh(['actionTaken']), 'job' => $job, 'invoice' => $invoice, 'payment' => $payment];
        });

        $this->notifyCompletion($result['job'], $result['invoice'], $data['payment_method'] ?? null);

        return $result;
    }

    private function notifyCompletion(ServiceJob $job, $invoice, ?string $method): void
    {
        if ($job->status !== JobStatus::Completed) {
            return;
        }
        $currency = Tenant::whereKey($job->tenant_id)->value('currency') ?? 'INR';
        $link = $invoice && $invoice->balance_amount > 0 && Tenant::find($job->tenant_id)?->onlinePaymentsEnabled()
            ? 'Pay online: '.$this->invoices->payLink($invoice)
            : '';
        $this->notifications->notifyCustomer($job, 'job_completed', [
            'invoice_number' => $invoice?->invoice_number ?? '-',
            'amount' => Money::format($invoice?->total_amount ?? 0, $currency),
            'balance' => Money::format($invoice?->balance_amount ?? 0, $currency),
            'pay_link' => $link,
        ]);
    }

    private function ensureOpen(?JobVisit $visit): void
    {
        if (! $visit || ! $visit->isOpen()) {
            throw ValidationException::withMessages(['visit' => 'This visit is already closed and can no longer be changed.']);
        }
    }
}
