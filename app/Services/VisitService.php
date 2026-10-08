<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Models\ActionTakenOption;
use App\Models\InventoryItem;
use App\Models\JobImage;
use App\Models\JobInventoryUsage;
use App\Models\JobVisit;
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
            if (! in_array($job->status, [JobStatus::Open, JobStatus::Pending], true)) {
                throw ValidationException::withMessages(['job' => $job->status === JobStatus::InProgress
                    ? 'A visit is already in progress for this job.'
                    : 'This job is '.$job->status->value.'.']);
            }
            if ($data['service_type'] === 'on_site' && (! isset($data['lat'], $data['lng']))) {
                // FR-6.2: location must be available before an on-site visit starts.
                throw ValidationException::withMessages(['location' => 'Turn on location services to start an on-site visit.']);
            }
            $open = JobVisit::where('technician_id', $technician->id)->where('status', 'in_progress')->with('job:id,crm_call_id')->first();
            if ($open) {
                throw ValidationException::withMessages(['job' => "Finish your active visit on job {$open->job?->crm_call_id} first."]);
            }

            $tz = Tenant::whereKey($job->tenant_id)->value('timezone') ?? config('app.timezone');
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
            $this->jobs->transition($job, JobStatus::InProgress, $technician->id, 'Service started ('.str_replace('_', ' ', $data['service_type']).')');

            return $visit;
        });
    }

    /** Call-tab edits while the visit is open (FR-6.5). */
    public function updateCallDetails(JobVisit $visit, array $data): JobVisit
    {
        $this->ensureOpen($visit);

        return DB::transaction(function () use ($visit, $data) {
            $job = $visit->job;
            $job->fill(array_intersect_key($data, array_flip(['complaint_details', 'complaint_type_id', 'complaint_summary_id', 'priority', 'call_type'])))->save();

            $customerFields = array_intersect_key($data, array_flip(['address', 'city', 'pincode', 'alt_phone']));
            if ($customerFields) {
                $job->customer->fill($customerFields)->save();
            }
            $productFields = array_intersect_key($data, array_flip(['serial_no', 'outdoor_serial_no']));
            if ($productFields && $job->customerProduct) {
                $job->customerProduct->fill($productFields)->save();
            }
            $visit->fill(array_intersect_key($data, array_flip(['service_summary', 'service_type'])))->save();

            return $visit;
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
        $this->inventory->returnUsage($usage, $user->id);
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

            $jobStatus = JobStatus::from($status === 'cancelled' ? 'cancelled' : $status);
            $this->jobs->transition($job, $jobStatus, $technician->id, 'Visit closed as '.$status.($visit->actionTaken ? ' – '.$visit->actionTaken->name : ''));
            if ($jobStatus === JobStatus::Cancelled) {
                $job->update(['cancel_reason' => $data['service_summary'] ?? 'Cancelled at visit']);
            }

            $invoice = $this->invoices->syncForJob($job);

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
