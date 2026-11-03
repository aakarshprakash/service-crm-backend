<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobImage;
use App\Models\JobInventoryUsage;
use App\Models\JobVisit;
use App\Models\JobVoiceNote;
use App\Models\ServiceJob;
use App\Models\TechnicianStock;
use App\Models\TechnicianStockMove;
use App\Services\VisitService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Technician service execution endpoints (§8.2 "Service Execution").
 */
class VisitController extends Controller
{
    public function __construct(private VisitService $visits) {}

    public function start(Request $request, int $jobId): JsonResponse
    {
        $data = $request->validate([
            'service_type' => ['required', Rule::in(['on_site', 'tele_call', 'office'])],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);
        $visit = $this->visits->start(ServiceJob::findOrFail($jobId), $request->user(), $data);

        return $this->created($visit, 'Service started.');
    }

    /** The technician's currently running visit, if any (resume after app restart). */
    public function active(Request $request): JsonResponse
    {
        $visit = JobVisit::where('technician_id', $request->user()->id)->where('status', 'in_progress')
            ->with('job:id,crm_call_id')->first();

        return $this->ok($visit);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $visit = $this->ownVisit($request, $id)->load([
            'images', 'voiceNotes', 'inventoryUsage.item:id,code,name,type,unit_of_measure', 'actionTaken:id,name',
        ]);

        return $this->ok($visit);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'service_type' => ['sometimes', Rule::in(['on_site', 'tele_call', 'office'])],
            'service_summary' => ['nullable', 'string', 'max:2000'],
            ...$this->callRules(),
        ]);
        $visit = $this->visits->updateCallDetails($this->ownVisit($request, $id), $data);

        return $this->ok($visit, 'Saved.');
    }

    /** "Check details" before starting: correct the call, address and serials on an assigned job. */
    public function prepare(Request $request, int $jobId): JsonResponse
    {
        $data = $request->validate($this->callRules());
        $job = $this->visits->prepareJob(ServiceJob::findOrFail($jobId), $request->user(), $data);

        return $this->ok($job, 'Details saved.');
    }

    /** Asking the office to cancel an assigned job without starting a visit. */
    public function requestCancel(Request $request, int $jobId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']], [
            'reason.required' => 'Write why this job should be cancelled.',
            'reason.min' => 'Write a clear reason (at least 10 characters) so the office can decide.',
        ]);
        $job = $this->visits->requestCancel(ServiceJob::findOrFail($jobId), $request->user(), trim($data['reason']));

        return $this->ok($job, 'Sent to the office for approval.');
    }

    /** Parts allocated to me, and what happened to them (with the job each was used on). */
    public function myParts(Request $request): JsonResponse
    {
        $id = $request->user()->id;

        return $this->ok([
            'stock' => TechnicianStock::where('technician_id', $id)->where('quantity', '>', 0)
                ->with('item:id,code,name,type,unit_of_measure,unit_price')->get()->sortBy('item.name')->values(),
            'moves' => TechnicianStockMove::where('technician_id', $id)->with(['item:id,code,name,unit_of_measure', 'job:id,crm_call_id'])
                ->latest('id')->limit(30)->get(),
        ]);
    }

    /** Job and product fields a technician may correct (FR-6.5). The customer's own details stay with the office. */
    private function callRules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'complaint_details' => ['nullable', 'string', 'max:2000'],
            'complaint_type_id' => ['nullable', 'integer', Rule::exists('complaint_types', 'id')->where('tenant_id', $tenantId)],
            'complaint_summary_id' => ['nullable', 'integer', Rule::exists('complaint_summaries', 'id')->where('tenant_id', $tenantId)],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'call_type' => ['sometimes', Rule::in(['crm_call', 'walk_in', 'referral', 'portal'])],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'outdoor_serial_no' => ['nullable', 'string', 'max:100'],
            'model_no' => ['nullable', 'string', 'max:150'],
            'category_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->where('tenant_id', $tenantId)],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('tenant_id', $tenantId)],
            'dealer_id' => ['nullable', 'integer', Rule::exists('dealers', 'id')->where('tenant_id', $tenantId)],
            'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
            'warranty_type' => ['nullable', Rule::in(['in_warranty', 'extended', 'amc', 'out_of_warranty'])],
            'warranty_expiry' => ['nullable', 'date'],
            'customer_satisfaction_code' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * FR-8.1 / FR-8.2 image upload. Clients downscale first; full-size camera photos are
     * still accepted and shrunk server-side (ImageOptimizer).
     */
    public function uploadImage(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(app(TenantContext::class)->tenant()->acceptedPhotoTypes())],
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:25600', 'dimensions:max_width=12000,max_height=12000'],
        ], [
            'type.in' => 'This photo section has been removed. Take the photo again under another section.',
            'image.max' => 'The photo is too large (over 25 MB). Retake it at a lower resolution.',
            'image.uploaded' => 'The photo could not be received. Check the connection and try again.',
        ]);
        $visit = $this->ownVisit($request, $id);
        if ($visit->images()->where('type', '!=', 'signature')->count() >= 30) {
            abort(422, 'Image limit reached for this visit.');
        }
        $image = $this->visits->uploadImage($visit, $data['type'], $data['image'], $request->user());

        return $this->created($image, 'Image uploaded.');
    }

    /** Customer signature captured on the technician's device after the work is done. */
    public function sign(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'signature' => ['required', 'file', 'mimes:png', 'max:2048', 'dimensions:min_width=100,min_height=50,max_width=4000,max_height=4000'],
            'signer_name' => ['required', 'string', 'max:100'],
        ], [
            'signer_name.required' => 'Enter the name of the person signing.',
        ]);
        $visit = $this->visits->sign($this->ownVisit($request, $id), $data['signature'], trim($data['signer_name']), $request->user());

        return $this->ok($visit, 'Signature saved.');
    }

    /** Voice note recorded on the phone (m4a / aac / webm / mp3 …). */
    public function uploadVoice(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'audio' => ['required', 'file', 'max:20480', 'mimetypes:audio/mp4,audio/x-m4a,audio/m4a,audio/aac,audio/mpeg,audio/ogg,audio/webm,audio/wav,audio/x-wav,audio/3gpp,video/mp4,video/3gpp,video/webm'],
            'duration' => ['nullable', 'integer', 'min:0', 'max:1800'],
        ], [
            'audio.mimetypes' => 'That file is not a supported voice recording.',
            'audio.max' => 'The voice note is too long (over 20 MB).',
        ]);
        $visit = $this->ownVisit($request, $id);
        if ($visit->voiceNotes()->count() >= 20) {
            abort(422, 'Voice note limit reached for this visit.');
        }
        $note = $this->visits->addVoiceNote($visit, $data['audio'], (int) ($data['duration'] ?? 0), $request->user());

        return $this->created($note, 'Voice note saved.');
    }

    public function deleteVoice(Request $request, int $id, int $noteId): JsonResponse
    {
        $visit = $this->ownVisit($request, $id);
        $this->visits->deleteVoiceNote(JobVoiceNote::where('job_visit_id', $visit->id)->findOrFail($noteId));

        return $this->ok(null, 'Voice note removed.');
    }

    /** Signed-URL voice note delivery (supports range requests for seeking). */
    public function voice(int $note): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $record = app(TenantContext::class)->withoutScope(fn () => JobVoiceNote::findOrFail($note));
        abort_unless(Storage::disk('private')->exists($record->file_path), 404);

        return response()->file(Storage::disk('private')->path($record->file_path), [
            'Content-Type' => $record->mime ?: 'audio/mp4',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function deleteImage(Request $request, int $id, int $imageId): JsonResponse
    {
        $visit = $this->ownVisit($request, $id);
        $this->visits->deleteImage(JobImage::where('job_visit_id', $visit->id)->findOrFail($imageId));

        return $this->ok(null, 'Image removed.');
    }

    /** FR-7.5 add spare / consumable to the visit. */
    public function addSpare(Request $request, int $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $data = $request->validate([
            'item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId)],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:100000', 'decimal:0,3'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'unit_price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'source' => ['nullable', Rule::in(['technician', 'branch'])],
        ]);
        $usage = $this->visits->addInventory($this->ownVisit($request, $id), $request->user(), $data);

        return $this->created($usage->load('item:id,code,name,type,unit_of_measure'), 'Item added.');
    }

    public function removeSpare(Request $request, int $id, int $usageId): JsonResponse
    {
        $visit = $this->ownVisit($request, $id);
        $this->visits->removeInventory($visit, JobInventoryUsage::findOrFail($usageId), $request->user());

        return $this->ok(null, 'Item removed and returned to stock.');
    }

    /** FR-6.6 / FR-6.7 close visit + on-the-spot payment collection. */
    public function complete(Request $request, int $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'part_pending', 'completed', 'cancelled'])],
            'action_taken_id' => ['nullable', 'integer', 'required_if:status,completed'],
            'service_summary' => ['required', 'string', 'max:2000', ...($request->input('status') === 'cancelled' ? ['min:10'] : [])],
            'customer_satisfaction_code' => ['nullable', 'string', 'max:50'],
            'is_gst' => ['nullable', 'boolean'],
            'assisted_staff_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'labour_charge' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'spare_charge' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'payment_method' => ['nullable', Rule::in(['cash', 'upi', 'cheque', 'bank_transfer', 'credit', 'online'])],
            'amount_collected' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ], [
            'action_taken_id.required_if' => 'Select the action taken to mark the visit completed.',
            'service_summary.required' => $request->input('status') === 'cancelled' ? 'Write why this job should be cancelled.' : 'Enter a short service summary.',
            'service_summary.min' => 'Write a clear reason (at least 10 characters) so the office can decide.',
        ]);

        $result = $this->visits->complete($this->ownVisit($request, $id), $request->user(), $data);

        return $this->ok($result, match ($data['status']) {
            'completed' => 'Visit completed.',
            'pending' => 'Visit saved as pending.',
            'part_pending' => 'Visit saved as part pending.',
            default => 'Visit closed. The office will review the cancellation.',
        });
    }

    /** Signed-URL image delivery from the private disk. */
    public function image(int $image): StreamedResponse
    {
        $record = app(TenantContext::class)->withoutScope(fn () => JobImage::findOrFail($image));
        abort_unless(Storage::disk('private')->exists($record->file_path), 404);

        return Storage::disk('private')->response($record->file_path, null, [
            'Cache-Control' => 'private, max-age=1800',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    private function ownVisit(Request $request, int $id): JobVisit
    {
        return JobVisit::where('technician_id', $request->user()->id)->findOrFail($id);
    }
}
