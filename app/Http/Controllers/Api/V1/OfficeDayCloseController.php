<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OfficeDayClose;
use App\Services\OfficeDayCloseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The office's end-of-day cash count (see OfficeDayCloseService). */
class OfficeDayCloseController extends Controller
{
    public function __construct(private OfficeDayCloseService $closes) {}

    /** What closing a day would look like, or the close already made for it. */
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return $this->ok($this->closes->preview($data['date']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'counted_cash' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'opening_cash' => ['nullable', 'integer', 'min:0', 'max:10000000000'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ], [], ['counted_cash' => 'cash counted', 'opening_cash' => 'cash before this day']);
        $close = $this->closes->close($data, $request->user());

        return $this->created($this->closes->present($close), 'Office day closed.');
    }

    public function destroy(int $id): JsonResponse
    {
        $this->closes->reopen(OfficeDayClose::findOrFail($id));

        return $this->ok(null, 'Office day reopened.');
    }
}
