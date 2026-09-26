<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\CashClose;
use App\Models\User;
use App\Services\CashCloseService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Accounts module – daily cash close (§5.15, §8.2 "Accounts").
 */
class CashCloseController extends Controller
{
    public function __construct(private CashCloseService $cash) {}

    /** FR-15.1 summary. Technicians see their own; accountants pick a technician. */
    public function summary(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'technician_id' => ['nullable', 'integer']]);
        $technician = $this->technician($request);
        $date = $request->input('date', now(app(TenantContext::class)->tenant()->timezone)->toDateString());

        return $this->ok($this->cash->summary($technician, $date));
    }

    /** FR-15.2 technician submits; FR-15.4 admin may force-close for a technician. */
    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'amount_confirmed' => ['required', 'integer', 'min:0', 'max:100000000'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'technician_id' => ['nullable', 'integer'],
        ]);
        $user = $request->user();
        if (! $user->isTechnician() && empty($data['technician_id'])) {
            abort(422, 'Select the technician to close cash for.');
        }
        $technician = $this->technician($request);
        $close = $this->cash->submit($technician, $data['date'], $data['amount_confirmed'], $data['remarks'] ?? null, $user);

        return $this->created($close, $close->force_closed ? 'Cash close submitted on behalf of the technician.' : 'Cash submitted for closing.');
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $closes = CashClose::with(['technician:id,name', 'branch:id,name', 'verifier:id,name'])
            ->when($user->isTechnician(), fn ($q) => $q->where('technician_id', $user->id))
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->string('status'))))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('technician_id'), fn ($q) => $q->where('technician_id', $request->integer('technician_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('close_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('close_date', '<=', $request->date('to')))
            ->when($request->boolean('discrepancy'), fn ($q) => $q->where(fn ($w) => $w->where('discrepancy_amount', '!=', 0)->orWhereColumn('amount_confirmed', '!=', 'expected_in_hand')))
            ->orderByDesc('close_date')->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($closes);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $close = CashClose::with(['technician:id,name,phone', 'branch:id,name', 'verifier:id,name', 'deposits.recorder:id,name'])->findOrFail($id);
        if ($request->user()->isTechnician() && $close->technician_id !== $request->user()->id) {
            abort(404);
        }
        $summary = $this->cash->summary($close->technician, $close->close_date->toDateString());

        return $this->ok(['close' => $close, 'payments' => $summary['payments']]);
    }

    /** FR-15.3 verify + discrepancy. */
    public function verify(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount_verified' => ['required', 'integer', 'min:0', 'max:100000000'],
            'discrepancy_remarks' => ['nullable', 'string', 'max:500'],
        ]);
        $close = $this->cash->verify(CashClose::findOrFail($id), $data['amount_verified'], $data['discrepancy_remarks'] ?? null, $request->user());

        return $this->ok($close, 'Cash close verified.');
    }

    public function deposit(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'deposited_to' => ['required', Rule::in(['office', 'bank'])],
            'reference_no' => ['nullable', 'string', 'max:100', 'required_if:deposited_to,bank'],
            // "today" in the company's timezone, not the server's.
            'deposit_date' => ['required', 'date', 'before_or_equal:'.now(app(TenantContext::class)->tenant()->timezone)->toDateString()],
            'remarks' => ['nullable', 'string', 'max:500'],
        ], ['reference_no.required_if' => 'Enter the bank deposit reference.']);
        $deposit = $this->cash->deposit(CashClose::findOrFail($id), $data, $request->user());

        return $this->created($deposit, 'Deposit recorded.');
    }

    /** Running cash-in-hand ledger per technician (FR-15.5). */
    public function ledger(Request $request): JsonResponse
    {
        return $this->ok($this->cash->ledger($this->technician($request)));
    }

    /** Cash in hand for every technician (accounts overview). */
    public function overview(): JsonResponse
    {
        $rows = User::inTenant()->technicians()->where('status', '!=', 'invited')->with('branch:id,name')->orderBy('name')->get()
            ->map(fn (User $t) => [
                'technician' => ['id' => $t->id, 'name' => $t->name, 'branch' => $t->branch?->name],
                'cash_in_hand' => $this->cash->cashInHand($t),
                'last_close' => CashClose::where('technician_id', $t->id)->orderByDesc('close_date')->first(['id', 'close_date', 'status']),
            ]);
        $pendingVerification = CashClose::where('status', 'submitted')->count();

        return $this->ok(['technicians' => $rows, 'pending_verification' => $pendingVerification]);
    }

    private function technician(Request $request): User
    {
        $user = $request->user();
        if ($user->isTechnician()) {
            return $user;
        }
        abort_unless($request->filled('technician_id'), 422, 'Select a technician.');

        return User::inTenant()->where('role', Role::Technician->value)->findOrFail($request->integer('technician_id'));
    }
}
