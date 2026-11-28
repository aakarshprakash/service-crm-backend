<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Enquiry;
use App\Services\EnquiryService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Enquiries (leads) for a repair or a sale, and their follow-ups. */
class EnquiryController extends Controller
{
    private const NAMES = [
        'follow_up_at' => 'follow-up date',
        'assigned_to' => 'person following up',
        'expected_value' => 'expected value',
        'brand_id' => 'brand',
        'branch_id' => 'branch',
    ];

    private const MESSAGES = [
        'phone.regex' => 'Enter a phone number with 10 to 15 digits.',
        'name.required' => 'Enter the name of the person who asked.',
        'phone.required' => 'Enter their phone number so you can call back.',
    ];

    public function __construct(private EnquiryService $enquiries) {}

    public function index(Request $request): JsonResponse
    {
        $rows = $this->filtered($request)
            ->with(['customer:id,name', 'assignee:id,name', 'brand:id,name', 'job:id,crm_call_id', 'estimate:id,estimate_number,status'])
            // Open ones by when to call next (overdue first), then the newest.
            ->orderByRaw("CASE WHEN status IN ('new','follow_up') THEN 0 ELSE 1 END")
            ->orderByRaw('follow_up_at IS NULL')->orderBy('follow_up_at')->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($rows);
    }

    /** How many are new, waiting for a follow-up today or overdue, and how they ended this month. */
    public function summary(Request $request): JsonResponse
    {
        $tz = app(TenantContext::class)->tenant()->timezone;
        $now = CarbonImmutable::now($tz);
        $endOfToday = $now->endOfDay()->utc();
        $startOfToday = $now->startOfDay()->utc();
        $monthStart = $now->startOfMonth()->utc();
        $base = fn () => Enquiry::query()->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', $request->user()->id));

        return $this->ok([
            'new' => $base()->where('status', 'new')->count(),
            'follow_up' => $base()->where('status', 'follow_up')->count(),
            'open' => $base()->whereIn('status', Enquiry::OPEN)->count(),
            'due_today' => $base()->whereIn('status', Enquiry::OPEN)->whereBetween('follow_up_at', [$startOfToday, $endOfToday])->count(),
            'overdue' => $base()->whereIn('status', Enquiry::OPEN)->where('follow_up_at', '<', $startOfToday)->count(),
            'converted_this_month' => $base()->where('status', 'converted')->where('closed_at', '>=', $monthStart)->count(),
            'lost_this_month' => $base()->where('status', 'lost')->where('closed_at', '>=', $monthStart)->count(),
            'received_this_month' => $base()->where('created_at', '>=', $monthStart)->count(),
            'open_value' => (int) $base()->whereIn('status', Enquiry::OPEN)->sum('expected_value'),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return $this->ok($this->present(Enquiry::findOrFail($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $enquiry = $this->enquiries->create($request->validate($this->rules(), self::MESSAGES, self::NAMES), $request->user());

        return $this->created($this->present($enquiry), "Enquiry {$enquiry->enquiry_number} saved.");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $enquiry = $this->enquiries->update(Enquiry::findOrFail($id), $request->validate($this->rules(true), self::MESSAGES, self::NAMES), $request->user());

        return $this->ok($this->present($enquiry), 'Enquiry saved.');
    }

    /** A follow-up note, optionally with the next date to call. */
    public function note(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
            'follow_up_at' => ['nullable', 'date'],
        ], ['note.required' => 'Write what was said.'], self::NAMES);
        $enquiry = $this->enquiries->addNote(Enquiry::findOrFail($id), $request->user(), $data['note'], $data['follow_up_at'] ?? null, $request->has('follow_up_at'));

        return $this->ok($this->present($enquiry), 'Follow-up saved.');
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['converted', 'lost', 'reopen'])],
            'reason' => ['nullable', 'string', 'max:300', 'required_if:status,lost'],
        ], ['reason.required_if' => 'Say why it was lost, e.g. price too high or bought elsewhere.']);
        $enquiry = Enquiry::findOrFail($id);
        $enquiry = match ($data['status']) {
            'lost' => $this->enquiries->markLost($enquiry, $request->user(), $data['reason']),
            'converted' => $this->enquiries->markConverted($enquiry, $request->user(), $data['reason'] ?? null),
            'reopen' => $this->enquiries->reopen($enquiry, $request->user()),
        };

        return $this->ok($this->present($enquiry), match ($data['status']) {
            'lost' => 'Marked as lost.', 'converted' => 'Marked as converted.', 'reopen' => 'Enquiry reopened.',
        });
    }

    /** Makes (or finds) the customer, so the job or quotation form can open with them chosen. */
    public function customer(Request $request, int $id): JsonResponse
    {
        $customer = $this->enquiries->customerFor(Enquiry::findOrFail($id), $request->user());

        return $this->ok(['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone]);
    }

    public function destroy(int $id): JsonResponse
    {
        Enquiry::findOrFail($id)->delete();

        return $this->ok(null, 'Enquiry deleted.');
    }

    private function present(Enquiry $enquiry): array
    {
        $enquiry->load(['customer:id,name,phone', 'assignee:id,name', 'brand:id,name', 'branch:id,name', 'creator:id,name',
            'job:id,crm_call_id,status', 'estimate:id,estimate_number,status,total_amount', 'notes.user:id,name']);

        return $enquiry->toArray();
    }

    private function filtered(Request $request): Builder
    {
        $tz = app(TenantContext::class)->tenant()->timezone;
        $startOfToday = CarbonImmutable::now($tz)->startOfDay()->utc();
        $endOfToday = CarbonImmutable::now($tz)->endOfDay()->utc();

        return Enquiry::query()
            ->when($request->filled('status'), fn ($q) => match ((string) $request->input('status')) {
                'open' => $q->whereIn('status', Enquiry::OPEN),
                'due' => $q->whereIn('status', Enquiry::OPEN)->where('follow_up_at', '<=', $endOfToday),
                'overdue' => $q->whereIn('status', Enquiry::OPEN)->where('follow_up_at', '<', $startOfToday),
                default => $q->where('status', $request->input('status')),
            })
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')))
            ->when($request->filled('assigned_to'), fn ($q) => $request->input('assigned_to') === 'none' ? $q->whereNull('assigned_to') : $q->where('assigned_to', $request->integer('assigned_to')))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', CarbonImmutable::parse($request->input('from'), $tz)->startOfDay()->utc()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', CarbonImmutable::parse($request->input('to'), $tz)->endOfDay()->utc()))
            ->when($request->filled('search'), function ($q) use ($request) {
                $like = $this->like($request->string('search'));
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('enquiry_number', 'like', $like)
                    ->orWhere('product', 'like', $like)->orWhere('details', 'like', $like));
            });
    }

    private function rules(bool $update = false): array
    {
        $tenantId = app(TenantContext::class)->id();
        $exists = fn (string $t) => Rule::exists($t, 'id')->where('tenant_id', $tenantId);
        $req = $update ? 'sometimes' : 'required';

        return [
            'type' => [$req, Rule::in(Enquiry::TYPES)],
            'source' => ['sometimes', Rule::in(Enquiry::SOURCES)],
            'customer_id' => ['nullable', 'integer', $exists('customers')],
            'name' => [$req, 'string', 'max:150'],
            'phone' => [$req, 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'brand_id' => ['nullable', 'integer', $exists('brands')],
            'product' => ['nullable', 'string', 'max:150'],
            'details' => ['nullable', 'string', 'max:2000'],
            'expected_value' => ['nullable', 'integer', 'min:0', 'max:10000000000'],
            'follow_up_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'branch_id' => ['nullable', 'integer', $exists('branches')],
        ];
    }
}
