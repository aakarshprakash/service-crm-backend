<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * FR-13.9: large exports run in the background and are delivered as a download.
 */
class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public int $exportId)
    {
        $this->onQueue('exports');
    }

    public function handle(ReportService $reports, ReportExporter $exporter, NotificationService $notifications, TenantContext $context): void
    {
        $export = ReportExport::findOrFail($this->exportId);
        $tenant = Tenant::findOrFail($export->tenant_id);
        $export->update(['status' => 'processing']);

        $context->runAs($tenant->id, function () use ($export, $tenant, $reports, $exporter, $notifications) {
            $report = $reports->run($export->report, $export->filters ?? [], $tenant);
            $path = "tenants/{$tenant->id}/exports/".$exporter->filename($report, $export->format);
            Storage::disk('private')->put($path, $exporter->render($report, $tenant, $export->format));

            $export->update(['status' => 'ready', 'file_path' => $path, 'completed_at' => now()]);

            if ($user = User::find($export->user_id)) {
                $notifications->notifyUser($user, 'export_ready', 'Your report is ready',
                    "{$report->title} (".strtoupper($export->format).') is ready to download.', ['export_id' => $export->id]);
            }
        });
    }

    public function failed(Throwable $e): void
    {
        ReportExport::whereKey($this->exportId)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
    }
}
