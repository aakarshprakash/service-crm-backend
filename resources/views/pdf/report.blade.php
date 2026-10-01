<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $report->title }}</title>
<style>
    @page { margin: 24px 24px 36px; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { font-size: 8.5px; color: #1f2937; }
    h1 { font-size: 15px; margin: 0; color: #0f172a; }
    .muted { color: #6b7280; }
    table { width: 100%; border-collapse: collapse; }
    .data th { background: #0f172a; color: #fff; padding: 5px 4px; text-align: left; font-size: 8px; }
    .data td { padding: 4px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
    .data tr:nth-child(even) td { background: #f9fafb; }
    .data tr.flag td { background: #fef3c7; }
    .num { text-align: right; white-space: nowrap; }
    .summary td { padding: 3px 8px 3px 0; }
    .footer { position: fixed; bottom: -22px; left: 0; right: 0; font-size: 8px; color: #9ca3af; }
</style>
</head>
<body>
<table>
    <tr>
        <td><h1>{{ $report->title }}</h1><div class="muted">{{ $tenant->name }} · {{ $report->subtitle }}</div></td>
        <td style="text-align:right" class="muted">Generated {{ now($tenant->timezone)->format('d M Y, h:i A') }}</td>
    </tr>
</table>

@if (! empty($report->summary))
    <table class="summary" style="margin: 10px 0; width: auto">
        <tr>
        @foreach ($report->summary as $label => $value)
            <td><span class="muted">{{ $label }}:</span> <strong>{{ $value }}</strong></td>
        @endforeach
        </tr>
    </table>
@endif

<table class="data">
    <thead>
        <tr>
        @foreach ($report->columns as $col)
            <th class="{{ in_array($col[1] ?? 'text', ['money', 'number', 'quantity']) ? 'num' : '' }}">{{ $col[0] }}</th>
        @endforeach
        </tr>
    </thead>
    <tbody>
    @forelse ($report->rows as $row)
        <tr class="{{ ! empty($row['_flag']) ? 'flag' : '' }}">
        @foreach ($report->columns as $key => $col)
            <td class="{{ in_array($col[1] ?? 'text', ['money', 'number', 'quantity']) ? 'num' : '' }}">{{ $format($row[$key] ?? null, $col[1] ?? 'text') }}</td>
        @endforeach
        </tr>
    @empty
        <tr><td colspan="{{ count($report->columns) }}" class="muted" style="text-align:center; padding:16px">No data for the selected filters.</td></tr>
    @endforelse
    </tbody>
</table>

<div class="footer">{{ $tenant->name }} · {{ $report->title }} · {{ count($report->rows) }} rows</div>
</body>
</html>
