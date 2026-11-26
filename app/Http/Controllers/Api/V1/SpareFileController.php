<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SpareImport;
use App\Services\Spares\SpareExporter;
use App\Services\Spares\SpareImportService;
use App\Services\Spares\SpareQuery;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** SAPRE Excel in and out: uploads (with a preview), the blank template, the export and the upload history. */
class SpareFileController extends Controller
{
    private const FILE_RULES = ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt'];

    private const FILE_MESSAGES = [
        'file.required' => 'Choose the Excel file to upload.',
        'file.mimes' => 'Upload an Excel file (.xlsx, .xls or .csv).',
        'file.max' => 'The file is larger than 10 MB.',
    ];

    public function __construct(private SpareImportService $imports, private SpareExporter $exporter) {}

    /** A Lloyd bill or the filled SAPRE template: new lines are added, lines already here updated. */
    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => self::FILE_RULES, 'dry_run' => ['boolean']], self::FILE_MESSAGES);
        $dryRun = $request->boolean('dry_run');
        $r = $this->imports->import($request->file('file'), $request->user(), $dryRun);

        $message = $dryRun ? '' : (implode(' ', array_filter([
            $r['new'] ? ($r['new'] === 1 ? '1 part added.' : "{$r['new']} parts added.") : null,
            $r['updated'] ? ($r['updated'] === 1 ? '1 part updated.' : "{$r['updated']} parts updated.") : null,
            $r['rejected'] ? "{$r['rejected']} rows could not be read." : null,
        ])) ?: 'Nothing new in this file.');

        return $this->ok($r, $message);
    }

    /** Lloyd's list of returns it is still waiting for. */
    public function companyPending(Request $request): JsonResponse
    {
        $request->validate(['file' => self::FILE_RULES, 'dry_run' => ['boolean']], self::FILE_MESSAGES);
        $dryRun = $request->boolean('dry_run');
        $r = $this->imports->companyPending($request->file('file'), $request->user(), $dryRun);

        return $this->ok($r, $dryRun ? '' : "Lloyd's pending list updated: {$r['matched']} pending, {$r['received']} marked received by Lloyd.");
    }

    public function export(Request $request): BinaryFileResponse
    {
        $filters = $request->validate(SpareQuery::rules());

        return $this->download($this->exporter->write(SpareQuery::apply($filters)), 'sapre-spare-parts');
    }

    public function template(): BinaryFileResponse
    {
        return $this->download($this->exporter->template(), 'sapre-template', dated: false);
    }

    /** Every upload: who, when, which file, and what it did. */
    public function history(Request $request): JsonResponse
    {
        return $this->paginated(SpareImport::with('user:id,name')->latest('id')->paginate($this->perPage($request, 20)));
    }

    private function download(string $path, string $name, bool $dated = true): BinaryFileResponse
    {
        $tz = app(TenantContext::class)->tenant()->timezone;
        $file = $name.($dated ? '-'.now($tz)->format('Y-m-d') : '').'.xlsx';

        return response()->download($path, $file, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
