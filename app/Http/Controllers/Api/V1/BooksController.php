<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashClose;
use App\Models\FundTransfer;
use App\Models\User;
use App\Services\BooksService;
use App\Services\CashCloseService;
use App\Services\LedgerService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Mini accounts: income vs expenses and a day book (all money in and out). */
class BooksController extends Controller
{
    public function __construct(private BooksService $books) {}

    public function summary(Request $request): JsonResponse
    {
        [$from, $to, $branch] = $this->period($request);

        return $this->ok($this->books->summary($from, $to, $this->tz(), $branch));
    }

    public function dayBook(Request $request): JsonResponse
    {
        [$from, $to, $branch] = $this->period($request);
        $rows = $this->books->dayBook($from, $to, $this->tz(), $branch);

        return $this->ok($rows, '', 200, [
            'in' => array_sum(array_column($rows, 'in')),
            'out' => array_sum(array_column($rows, 'out')),
        ]);
    }

    /** Accounts home: this month at a glance, balances, receivables and a 6-month trend. */
    public function overview(LedgerService $ledger, CashCloseService $cash): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();
        $now = CarbonImmutable::now($tenant->timezone);
        $month = $this->books->summary($now->startOfMonth()->toDateString(), $now->toDateString(), $tenant->timezone);
        $lastStart = $now->startOfMonth()->subMonth();
        $last = $this->books->summary($lastStart->toDateString(), $lastStart->endOfMonth()->toDateString(), $tenant->timezone);
        $receivables = $ledger->receivables();
        $withTechnicians = User::inTenant()->technicians()->where('status', 'active')->get()->sum(fn (User $t) => $cash->cashInHand($t));
        $recent = array_slice(array_reverse($this->books->dayBook($now->subDays(30)->toDateString(), $now->toDateString(), $tenant->timezone, null, 500)), 0, 8);

        return $this->ok([
            'month' => [
                'income' => $month['income'], 'expense' => $month['expense'], 'net' => $month['net'],
                'income_by_source' => $month['income_by_source'], 'expense_by_category' => $month['expense_by_category'],
            ],
            'last_month' => ['income' => $last['income'], 'expense' => $last['expense'], 'net' => $last['net']],
            'balances' => ['cash' => $ledger->balance($tenant, 'cash'), 'bank' => $ledger->balance($tenant, 'bank'), 'with_technicians' => (int) $withTechnicians],
            'receivables' => ['total' => $receivables['totals']['total'], 'customers' => $receivables['customers'], 'buckets' => $receivables['totals']],
            'pending_cash_closes' => CashClose::where('status', 'submitted')->count(),
            'trend' => $ledger->monthlyTrend($tenant),
            'recent' => $recent,
            'opening' => $ledger->opening($tenant),
        ]);
    }

    /** Cash book or bank book with running balance. */
    public function ledger(Request $request, LedgerService $ledger): JsonResponse
    {
        $request->validate(['book' => ['required', Rule::in(LedgerService::BOOKS)]]);
        [$from, $to] = $this->period($request);

        $tenant = app(TenantContext::class)->tenant();

        return $this->ok($ledger->ledger($tenant, $request->string('book'), $from, $to) + ['opening_settings' => $ledger->opening($tenant)]);
    }

    public function receivables(Request $request, LedgerService $ledger): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'branch_id' => ['nullable', 'integer']]);

        return $this->ok($ledger->receivables($request->input('search'), $request->integer('branch_id') ?: null));
    }

    public function profitLoss(Request $request, LedgerService $ledger): JsonResponse
    {
        [$from, $to, $branch] = $this->period($request);

        return $this->ok($ledger->profitLoss(app(TenantContext::class)->tenant(), $from, $to, $branch));
    }

    /** Opening cash & bank balances the books start from. */
    public function updateOpening(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'cash' => ['required', 'integer', 'min:-10000000000', 'max:10000000000'],
            'bank' => ['required', 'integer', 'min:-10000000000', 'max:10000000000'],
        ]);
        $tenant = app(TenantContext::class)->tenant();
        $settings = $tenant->mergedSettings();
        $settings['books'] = ['opening_date' => $data['date'], 'opening_cash' => $data['cash'], 'opening_bank' => $data['bank']];
        $tenant->update(['settings' => $settings]);

        return $this->ok($data, 'Opening balances saved.');
    }

    public function transfers(Request $request): JsonResponse
    {
        $rows = FundTransfer::with('creator:id,name')
            ->when($request->filled('from'), fn ($q) => $q->where('transfer_date', '>=', $request->date('from')->toDateString()))
            ->when($request->filled('to'), fn ($q) => $q->where('transfer_date', '<=', $request->date('to')->toDateString()))
            ->orderByDesc('transfer_date')->orderByDesc('id')->paginate($this->perPage($request));

        return $this->paginated($rows);
    }

    public function storeTransfer(Request $request): JsonResponse
    {
        $today = CarbonImmutable::now($this->tz())->toDateString();
        $data = $request->validate([
            'transfer_date' => ['required', 'date', 'before_or_equal:'.$today],
            'direction' => ['required', Rule::in(FundTransfer::DIRECTIONS)],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000000'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $transfer = FundTransfer::create($data + ['created_by' => $request->user()->id]);

        return $this->created($transfer, $transfer->direction === 'cash_to_bank' ? 'Cash deposit recorded.' : 'Cash withdrawal recorded.');
    }

    public function destroyTransfer(int $id): JsonResponse
    {
        FundTransfer::findOrFail($id)->delete();

        return $this->ok(null, 'Transfer deleted.');
    }

    /** @return array{0: string, 1: string, 2: ?int} */
    private function period(Request $request): array
    {
        $tenantId = app(TenantContext::class)->id();
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
        ]);
        $today = CarbonImmutable::now($this->tz());
        $from = $data['from'] ?? $today->startOfMonth()->toDateString();
        $to = $data['to'] ?? $today->toDateString();
        abort_if(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366, 422, 'Choose a period of one year or less.');

        return [$from, $to, $data['branch_id'] ?? null];
    }

    private function tz(): string
    {
        return app(TenantContext::class)->tenant()->timezone;
    }
}
