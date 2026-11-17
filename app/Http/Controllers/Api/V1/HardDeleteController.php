<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\HardDeleteService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Permanent deletion of test entries. See HardDeleteService for the rules. */
class HardDeleteController extends Controller
{
    public function __construct(private HardDeleteService $service) {}

    public function status(): JsonResponse
    {
        return $this->ok($this->service->status() + ['hours' => HardDeleteService::HOURS_ON]);
    }

    /** Switching it on (or off) needs the admin's own password: it changes what the accounts say. */
    public function toggle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'password' => ['required', 'string', 'current_password'],
        ], ['password.current_password' => 'That is not your password.']);
        $this->service->set(app(TenantContext::class)->tenant(), (bool) $data['enabled'], $request->user());

        return $this->ok($this->service->status() + ['hours' => HardDeleteService::HOURS_ON], $data['enabled']
            ? 'Permanent delete is on for '.HardDeleteService::HOURS_ON.' hours.'
            : 'Permanent delete is off.');
    }

    public function preview(string $type, int $id): JsonResponse
    {
        return $this->ok($this->service->preview($type, $id) + ['enabled' => $this->service->enabled()]);
    }

    /** The record's number (or name) typed back is the confirmation: no deleting the wrong one by a slip. */
    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        $this->service->assertEnabled();
        $preview = $this->service->preview($type, $id);
        if ($preview['blocked']) {
            throw ValidationException::withMessages(['hard_delete' => $preview['blocked']]);
        }
        $typed = trim((string) $request->input('confirm'));
        if (mb_strtolower($typed) !== mb_strtolower(trim($preview['confirm']))) {
            throw ValidationException::withMessages(['confirm' => "Type {$preview['confirm']} to confirm."]);
        }

        return $this->ok(null, $this->service->delete($type, $id, $request->user()));
    }
}
