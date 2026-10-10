<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseClaim;
use App\Models\ServiceJob;
use App\Services\ExpenseClaimService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Field expense claims: staff submit (/my/expenses), the office approves (/expense-claims). */
class ExpenseClaimController extends Controller
{
    public function __construct(private ExpenseClaimService $claims) {}

    // ---- claimant -----------------------------------------------------------

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = ExpenseClaim::where('user_id', $user->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->with(['category:id,name', 'job:id,crm_call_id'])
            ->orderByDesc('claim_date')->orderByDesc('id')
            ->paginate($this->perPage($request, 30));

        $tz = app(TenantContext::class)->tenant()->timezone;
        $monthStart = now($tz)->startOfMonth()->toDateString();

        return $this->paginated($rows, null, [
            'pending_amount' => (int) ExpenseClaim::where('user_id', $user->id)->where('status', 'pending')->sum('amount'),
            'month_amount' => (int) ExpenseClaim::where('user_id', $user->id)->where('status', '!=', 'rejected')->whereDate('claim_date', '>=', $monthStart)->sum('amount'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();
        $user = $request->user();
        $data = $request->validate([
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('tenant_id', $tenant->id)->where('is_active', true)],
            'amount' => ['required', 'integer', 'min:100', 'max:10000000'],
            'claim_date' => ['required', 'date', 'before_or_equal:'.now($tenant->timezone)->toDateString(), 'after_or_equal:'.now($tenant->timezone)->subDays(60)->toDateString()],
            'paid_from' => ['required', Rule::in(ExpenseClaim::PAID_FROM)],
            'job_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.min' => 'Enter an amount of at least 1.',
            'claim_date.after_or_equal' => 'Expenses older than 60 days can\'t be claimed here. Contact the office.',
        ]);
        if (! empty($data['job_id'])) {
            $job = ServiceJob::find($data['job_id']);
            // Technicians can only link their own jobs.
            if (! $job || ($user->role === Role::Technician && $job->assigned_technician_id !== $user->id)) {
                throw ValidationException::withMessages(['job_id' => 'Select one of your jobs.']);
            }
        }
        if ($data['paid_from'] === 'cash_in_hand' && $user->role !== Role::Technician) {
            throw ValidationException::withMessages(['paid_from' => 'Only technicians carry collected cash.']);
        }

        $claim = $this->claims->submit($user, $data);

        return $this->created($claim->load(['category:id,name', 'job:id,crm_call_id']), 'Expense submitted for approval.');
    }

    public function receipt(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'receipt' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:25600', 'dimensions:max_width=12000,max_height=12000'],
        ], ['receipt.max' => 'The photo is too large (over 25 MB).']);
        $claim = ExpenseClaim::where('user_id', $request->user()->id)->findOrFail($id);

        return $this->ok($this->claims->attachReceipt($claim, $data['receipt']), 'Receipt attached.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->claims->withdraw(ExpenseClaim::where('user_id', $request->user()->id)->findOrFail($id));

        return $this->ok(null, 'Expense claim withdrawn.');
    }

    // ---- office ---------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $rows = ExpenseClaim::query()
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->string('status'))))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->with(['user:id,name,role', 'category:id,name', 'job:id,crm_call_id', 'decider:id,name', 'cashClose:id,close_date,status'])
            ->orderByRaw("status = 'pending' desc")->orderByDesc('claim_date')->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($rows, null, [
            'pending_count' => ExpenseClaim::where('status', 'pending')->count(),
            'pending_amount' => (int) ExpenseClaim::where('status', 'pending')->sum('amount'),
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['nullable', Rule::in(Expense::METHODS)],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $claim = $this->claims->approve(ExpenseClaim::findOrFail($id), $request->user(), $data['payment_method'] ?? null, $data['note'] ?? null);

        return $this->ok($claim->load(['user:id,name', 'category:id,name', 'decider:id,name']), 'Expense approved and booked.');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:300']], ['note.required' => 'Give the reason for rejecting.']);
        $claim = $this->claims->reject(ExpenseClaim::findOrFail($id), $request->user(), $data['note']);

        return $this->ok($claim->load(['user:id,name', 'category:id,name', 'decider:id,name']), 'Expense claim rejected.');
    }

    /** Signed-URL receipt delivery from the private disk. */
    public function receiptFile(int $claim): StreamedResponse
    {
        $record = app(TenantContext::class)->withoutScope(fn () => ExpenseClaim::findOrFail($claim));
        abort_unless($record->receipt_path && Storage::disk('private')->exists($record->receipt_path), 404);

        return Storage::disk('private')->response($record->receipt_path, null, [
            'Cache-Control' => 'private, max-age=1800',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
