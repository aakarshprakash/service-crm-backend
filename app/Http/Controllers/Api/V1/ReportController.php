<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportExport;
use App\Models\ReportExport;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Report Center (§5.13): on-screen view + Excel / PDF export for every report.
 */
class ReportController extends Controller
{
    /** Rows shown on screen; exports always contain everything. */
    private const SCREEN_LIMIT = 1000;

    /** Above this, exports are generated in the background (FR-13.9). */
    private const SYNC_EXPORT_LIMIT = 3000;

    public function __construct(private ReportService $reports, private ReportExporter $exporter) {}

    public function index(Request $request): JsonResponse
    {
        $available = collect(ReportService::REPORTS)
            ->filter(fn ($r) => Gate::allows($r[1]))
            ->map(fn ($r, $key) => ['key' => $key, 'title' => $r[0], 'description' => $r[2], 'financial' => $r[1] === 'reports.financial'])
            ->values();

        return $this->ok($available);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        $filters = $this->filters($request, $report);
        $result = $this->reports->run($report, $filters, app(TenantContext::class)->tenant(), self::SCREEN_LIMIT + 1);
        $truncated = count($result->rows) > self::SCREEN_LIMIT;
        if ($truncated) {
            $result->rows = array_slice($result->rows, 0, self::SCREEN_LIMIT);
        }

        return $this->ok($result->toArray() + ['truncated' => $truncated]);
    }

    public function export(Request $request, string $report): Response
    {
        $filters = $this->filters($request, $report);
        $format = $request->validate(['format' => ['required', Rule::in(['xlsx', 'pdf'])]])['format'];
        $tenant = app(TenantContext::class)->tenant();

        $probe = $this->reports->run($report, $filters, $tenant, self::SYNC_EXPORT_LIMIT + 1);
        if (count($probe->rows) > self::SYNC_EXPORT_LIMIT) {
            $export = ReportExport::create([
                'tenant_id' => $tenant->id,
                'user_id' => $request->user()->id,
                'report' => $report,
                'format' => $format,
                'filters' => $filters,
            ]);
            GenerateReportExport::dispatch($export->id);

            return $this->ok(['export_id' => $export->id, 'queued' => true],
                'This is a large report. We are preparing it and will notify you when it is ready.', 202);
        }

        $result = $this->reports->run($report, $filters, $tenant);
        $content = $this->exporter->render($result, $tenant, $format);
        $filename = $this->exporter->filename($result, $format);

        return response($content, 200, [
            'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function exports(Request $request): JsonResponse
    {
        $rows = ReportExport::where('user_id', $request->user()->id)->latest('id')->limit(30)->get()
            ->map(fn ($e) => $e->toArray() + ['title' => ReportService::REPORTS[$e->report][0] ?? $e->report]);

        return $this->ok($rows);
    }

    public function download(Request $request, int $id): Response
    {
        $export = ReportExport::where('user_id', $request->user()->id)->where('status', 'ready')->findOrFail($id);
        abort_unless(Storage::disk('private')->exists($export->file_path), 404);

        return Storage::disk('private')->download($export->file_path, basename($export->file_path), ['Cache-Control' => 'no-store, private']);
    }

    private function filters(Request $request, string $report): array
    {
        abort_unless(isset(ReportService::REPORTS[$report]), 404);
        abort_unless(Gate::allows(ReportService::REPORTS[$report][1]), 403);
        $tenantId = app(TenantContext::class)->id();

        return $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'technician_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'status' => ['nullable', 'string', 'max:20'],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'complaint_type_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(['spare', 'consumable'])],
            'item_id' => ['nullable', 'integer'],
            'group_by' => ['nullable', Rule::in(['day', 'month', 'technician', 'branch'])],
            'credit_only' => ['nullable', 'boolean'],
        ]);
    }
}
