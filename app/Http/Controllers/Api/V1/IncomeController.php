<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Income;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Money received without an invoice: a service charge taken on the spot, a part sold over the counter, scrap,
 * commission. It lands in the cash or bank book by its method, and in the day book, register and profit & loss.
 */
class IncomeController extends Controller
{
    /** Field names as people say them, for error messages ("The category field is required."). */
    private const NAMES = [
        'income_date' => 'date',
        'income_category_id' => 'category',
        'payment_method' => 'payment method',
        'received_from' => 'received from',
        'reference_no' => 'reference number',
        'user_id' => 'staff member',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = $this->filtered($request);
        $byCategory = (clone $query)->reorder()->leftJoin('income_categories as c', 'c.id', '=', 'incomes.income_category_id')
            ->selectRaw("COALESCE(c.name, 'Uncategorised') as category, SUM(incomes.amount) as total")
            ->groupBy('category')->orderByDesc('total')->pluck('total', 'category')->map(fn ($v) => (int) $v);

        $rows = $query->with(['category:id,name', 'branch:id,name', 'user:id,name', 'creator:id,name'])
            ->orderByDesc('income_date')->orderByDesc('id')
            ->paginate($this->perPage($request));

        // 'total' stays the row count the pager needs; the money sum goes in 'total_amount'.
        return $this->paginated($rows, null, ['total_amount' => (int) $byCategory->sum(), 'by_category' => $byCategory]);
    }

    public function store(Request $request): JsonResponse
    {
        $income = Income::create($request->validate($this->rules(), [], self::NAMES) + ['created_by' => $request->user()->id]);

        return $this->created($income->load(['category:id,name', 'branch:id,name', 'user:id,name']), 'Income recorded.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $income = Income::findOrFail($id);
        $income->update($request->validate($this->rules(true), [], self::NAMES));

        return $this->ok($income->load(['category:id,name', 'branch:id,name', 'user:id,name']), 'Income updated.');
    }

    public function destroy(int $id): JsonResponse
    {
        Income::findOrFail($id)->delete();

        return $this->ok(null, 'Income deleted.');
    }

    private function filtered(Request $request): Builder
    {
        return Income::query()
            ->when($request->filled('from'), fn ($q) => $q->whereDate('income_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('income_date', '<=', $request->date('to')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('income_category_id', $request->integer('category_id')))
            ->when($request->filled('payment_method'), fn ($q) => $q->where('payment_method', $request->string('payment_method')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $like = $this->like($request->string('search'));
                $q->where(fn ($w) => $w->where('received_from', 'like', $like)->orWhere('phone', 'like', $like)
                    ->orWhere('description', 'like', $like)->orWhere('reference_no', 'like', $like));
            });
    }

    private function rules(bool $update = false): array
    {
        $tenantId = app(TenantContext::class)->id();
        $exists = fn (string $t) => Rule::exists($t, 'id')->where('tenant_id', $tenantId);
        $req = $update ? 'sometimes' : 'required';
        // "Today" in the company's timezone, not the server's (UTC lags IST until 05:30).
        $today = now(app(TenantContext::class)->tenant()->timezone)->toDateString();

        return [
            'income_date' => [$req, 'date', 'before_or_equal:'.$today],
            'income_category_id' => [$req, 'integer', $exists('income_categories')],
            'amount' => [$req, 'integer', 'min:1', 'max:10000000000'],
            'payment_method' => [$req, Rule::in(Income::METHODS)],
            'received_from' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'reference_no' => ['nullable', 'string', 'max:100', Rule::requiredIf(fn () => in_array(request('payment_method'), ['cheque', 'bank_transfer'], true))],
            'description' => ['nullable', 'string', 'max:500'],
            'branch_id' => ['nullable', 'integer', $exists('branches')],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
