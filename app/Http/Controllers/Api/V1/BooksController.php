<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\BooksService;
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
