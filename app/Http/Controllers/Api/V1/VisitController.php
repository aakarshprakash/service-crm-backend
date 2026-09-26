<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobImage;
use App\Models\JobInventoryUsage;
use App\Models\JobVisit;
use App\Models\ServiceJob;
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
            'images', 'inventoryUsage.item:id,code,name,type,unit_of_measure', 'actionTaken:id,name',
        ]);

        return $this->ok($visit);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $data = $request->validate([
            'service_type' => ['sometimes', Rule::in(['on_site', 'tele_call', 'office'])],
            'service_summary' => ['nullable', 'string', 'max:2000'],
            'complaint_details' => ['nullable', 'string', 'max:2000'],
            'complaint_type_id' => ['nullable', 'integer', Rule::exists('complaint_types', 'id')->where('tenant_id', $tenantId)],
            'complaint_summary_id' => ['nullable', 'integer', Rule::exists('complaint_summaries', 'id')->where('tenant_id', $tenantId)],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'call_type' => ['sometimes', Rule::in(['crm_call', 'walk_in', 'referral', 'portal'])],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'alt_phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'outdoor_serial_no' => ['nullable', 'string', 'max:100'],
        ]);
        $visit = $this->visits->updateCallDetails($this->ownVisit($request, $id), $data);

        return $this->ok($visit, 'Saved.');
    }

    /** FR-8.1 / FR-8.2 image upload (client compresses before upload). */
    public function uploadImage(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['bill', 'serial', 'complaint_part', 'new_part', 'other'])],
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:max_width=8000,max_height=8000'],
        ]);
        $visit = $this->ownVisit($request, $id);
        if ($visit->images()->count() >= 30) {
            abort(422, 'Image limit reached for this visit.');
        }
        $image = $this->visits->uploadImage($visit, $data['type'], $data['image'], $request->user());

        return $this->created($image, 'Image uploaded.');
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
            'status' => ['required', Rule::in(['pending', 'completed', 'cancelled'])],
            'action_taken_id' => ['nullable', 'integer', 'required_if:status,completed'],
            'service_summary' => ['required', 'string', 'max:2000'],
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
            'service_summary.required' => 'Enter a short service summary.',
        ]);

        $result = $this->visits->complete($this->ownVisit($request, $id), $request->user(), $data);

        return $this->ok($result, match ($data['status']) {
            'completed' => 'Visit completed.',
            'pending' => 'Visit saved as pending.',
            default => 'Visit cancelled.',
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
