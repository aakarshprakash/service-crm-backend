<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\GeoLink;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Customer location for a job, from three sources:
 *  - the customer, through a share-location link sent by SMS / WhatsApp (public page);
 *  - the office, pasting a location the customer shared on WhatsApp / Google Maps;
 *  - the technician, on site ("I'm at the customer's place").
 * The location is stored on the customer and the assigned technician is notified.
 */
class JobLocationController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    /** Office: set from a pasted map link or coordinates. */
    public function set(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'link' => ['nullable', 'string', 'max:2000', 'required_without:lat'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ], ['link.required_without' => 'Paste the location link the customer shared.']);
        $point = isset($data['lat']) ? ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng']] : GeoLink::resolve($data['link']);
        if (! $point) {
            throw ValidationException::withMessages(['link' => 'Couldn\'t read a location from that link. Paste a Google Maps / WhatsApp location link or "latitude, longitude".']);
        }
        $job = ServiceJob::with('customer')->findOrFail($id);
        $this->store($job, $point, 'office', $request->user());

        return $this->ok($job->customer->only(['id', 'lat', 'lng', 'location_updated_at', 'location_source']), 'Customer location saved.');
    }

    /** Office: a link the customer opens to share their live location (optionally sent by SMS / WhatsApp). */
    public function requestLink(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['send' => ['boolean']]);
        $job = ServiceJob::with('customer')->findOrFail($id);
        if ($job->status->isFinal()) {
            throw ValidationException::withMessages(['job' => 'This job is closed.']);
        }
        if (! $job->location_token) {
            $job->forceFill(['location_token' => Str::random(40)])->save();
        }
        $link = rtrim(config('app.frontend_url'), '/').'/share-location/'.$job->location_token;
        $tenant = app(TenantContext::class)->tenant();
        $text = "Dear {$job->customer?->name}, please share your location for service request {$job->crm_call_id} so our technician can reach you: {$link} - {$tenant->name}";
        if ($data['send'] ?? false) {
            $this->notifications->notifyCustomer($job, 'location_request', ['location_link' => $link]);
        }

        return $this->ok(['link' => $link, 'message' => $text, 'phone' => $job->customer?->phone], ($data['send'] ?? false) ? 'Location request sent to the customer.' : '');
    }

    /** Technician on site: use the phone's current position. */
    public function fromSite(Request $request, int $visitId): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $visit = JobVisit::where('technician_id', $request->user()->id)->findOrFail($visitId);
        $job = ServiceJob::with('customer')->findOrFail($visit->job_id);
        $this->store($job, ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng']], 'technician', $request->user());

        return $this->ok($job->customer->only(['id', 'lat', 'lng', 'location_updated_at', 'location_source']), 'Customer location saved.');
    }

    // ---- public (customer) --------------------------------------------------------

    public function publicShow(string $token): JsonResponse
    {
        [$job, $tenant] = $this->resolve($token);

        return $this->ok([
            'company' => $tenant->name,
            'call_id' => $job->crm_call_id,
            'customer' => $job->customer?->name,
            'shared' => $job->customer?->location_source === 'customer' && $job->customer?->location_updated_at?->gt($job->created_at),
            'closed' => $job->status->isFinal(),
        ]);
    }

    public function publicStore(Request $request, string $token): JsonResponse
    {
        [$job] = $this->resolve($token);
        if ($job->status->isFinal()) {
            throw ValidationException::withMessages(['job' => 'This service request is already closed.']);
        }
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);
        $this->store($job, ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng']], 'customer', null);

        return $this->ok(null, 'Thank you! Your location has been shared with our technician.');
    }

    private function resolve(string $token): array
    {
        abort_unless(strlen($token) === 40 && ctype_alnum($token), 404);
        $job = app(TenantContext::class)->withoutScope(fn () => ServiceJob::where('location_token', $token)->firstOrFail());
        $tenant = Tenant::findOrFail($job->tenant_id);
        abort_unless($tenant->isUsable(), 404);
        app(TenantContext::class)->set($job->tenant_id);
        $job->load('customer');

        return [$job, $tenant];
    }

    /** @param array{lat: float, lng: float} $point */
    private function store(ServiceJob $job, array $point, string $source, ?User $by): void
    {
        $job->customer->update([
            'lat' => $point['lat'],
            'lng' => $point['lng'],
            'location_updated_at' => now(),
            'location_source' => $source,
        ]);

        $tech = $job->assigned_technician_id ? User::inTenant($job->tenant_id)->find($job->assigned_technician_id) : null;
        if ($tech && $tech->id !== $by?->id) {
            $this->notifications->notifyUser($tech, 'job_location', $source === 'customer' ? 'Customer shared location' : 'Customer location updated',
                "{$job->crm_call_id} · {$job->customer->name} · tap for directions", ['job_id' => $job->id]);
        }
        if ($source === 'customer' && $job->created_by && $job->created_by !== $tech?->id && ($creator = User::inTenant($job->tenant_id)->find($job->created_by))) {
            $this->notifications->notifyUser($creator, 'job_location', 'Customer shared location', "{$job->crm_call_id} · {$job->customer->name}", ['job_id' => $job->id]);
        }
    }
}
