<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Services\OfficeDayCloseService;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Business expenses (fuel, salaries, rent, purchases…) for the mini accounts module. */
class ExpenseController extends Controller
{
    /** Field names as people say them, for error messages ("The category field is required."). */
    private const NAMES = [
        'expense_date' => 'date',
        'expense_category_id' => 'category',
        'payment_method' => 'payment method',
        'paid_to' => 'paid to',
        'reference_no' => 'reference number',
        'user_id' => 'staff member',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = $this->filtered($request);
        $byCategory = (clone $query)->reorder()->leftJoin('expense_categories as c', 'c.id', '=', 'expenses.expense_category_id')
            ->selectRaw("COALESCE(c.name, 'Uncategorised') as category, SUM(expenses.amount) as total")
            ->groupBy('category')->orderByDesc('total')->pluck('total', 'category')->map(fn ($v) => (int) $v);

        $rows = $query->with(['category:id,name', 'branch:id,name', 'user:id,name', 'creator:id,name'])
            ->orderByDesc('expense_date')->orderByDesc('id')
            ->paginate($this->perPage($request));

        // 'total' stays the row count the pager needs; the money sum goes in 'total_amount'.
        return $this->paginated($rows, null, ['total_amount' => (int) $byCategory->sum(), 'by_category' => $byCategory]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(), [], self::NAMES);
        app(OfficeDayCloseService::class)->assertOpen($data['expense_date'], 'expense_date');
        $expense = Expense::create($data + ['created_by' => $request->user()->id]);

        return $this->created($expense->load(['category:id,name', 'branch:id,name', 'user:id,name']), 'Expense recorded.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $expense = Expense::findOrFail($id);
        $data = $request->validate($this->rules(true), [], self::NAMES);
        // Neither the day it was on nor the day it moves to may be closed.
        $days = app(OfficeDayCloseService::class);
        $days->assertOpen($expense->expense_date->toDateString(), 'expense_date');
        $days->assertOpen($data['expense_date'] ?? $expense->expense_date->toDateString(), 'expense_date');
        $expense->update($data);

        return $this->ok($expense->load(['category:id,name', 'branch:id,name', 'user:id,name']), 'Expense updated.');
    }

    public function destroy(int $id): JsonResponse
    {
        $expense = Expense::findOrFail($id);
        app(OfficeDayCloseService::class)->assertOpen($expense->expense_date->toDateString(), 'expense_date');
        $expense->delete();

        return $this->ok(null, 'Expense deleted.');
    }

    private function filtered(Request $request): Builder
    {
        return Expense::query()
            ->when($request->filled('from'), fn ($q) => $q->whereDate('expense_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('expense_date', '<=', $request->date('to')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('expense_category_id', $request->integer('category_id')))
            ->when($request->filled('payment_method'), fn ($q) => $q->where('payment_method', $request->string('payment_method')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $like = $this->like($request->string('search'));
                $q->where(fn ($w) => $w->where('paid_to', 'like', $like)->orWhere('description', 'like', $like)->orWhere('reference_no', 'like', $like));
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
            'expense_date' => [$req, 'date', 'before_or_equal:'.$today],
            'expense_category_id' => [$req, 'integer', $exists('expense_categories')],
            'amount' => [$req, 'integer', 'min:1', 'max:10000000000'],
            'payment_method' => [$req, Rule::in(Expense::METHODS)],
            'paid_to' => ['nullable', 'string', 'max:150'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'branch_id' => ['nullable', 'integer', $exists('branches')],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
