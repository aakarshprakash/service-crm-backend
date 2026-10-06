<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Payslip {{ $month }} – {{ $slip->user?->name }}</title>
<style>
    @page { margin: 28px 32px; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { font-size: 10.5px; color: #1f2937; }
    .muted { color: #6b7280; }
    .right { text-align: right; }
    h1 { font-size: 18px; margin: 0; color: #0f172a; }
    h2 { font-size: 10px; margin: 0 0 6px; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; }
    table { width: 100%; border-collapse: collapse; }
    .box { border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 12px; }
    .grid td { padding: 3px 0; }
    .lines th { background: #0f172a; color: #fff; text-align: left; padding: 6px 8px; font-size: 10px; }
    .lines td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
    .net { margin-top: 14px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 10px 12px; font-size: 13px; }
    .footer { margin-top: 28px; font-size: 9px; color: #9ca3af; text-align: center; }
</style>
</head>
<body>
@php
    $logo = null;
    if ($tenant->logo_path && \Illuminate\Support\Facades\Storage::disk('private')->exists($tenant->logo_path)) {
        $logo = 'data:image/png;base64,'.base64_encode(\Illuminate\Support\Facades\Storage::disk('private')->get($tenant->logo_path));
    }
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 1), '0'), '.');
    $earnings = $slip->earnings ?? [];
    if ($slip->bonus > 0) { $earnings[] = ['name' => 'Bonus / incentive', 'amount' => $slip->bonus]; }
    $deductions = $slip->deductions ?? [];
    if ($slip->lop_amount > 0) { $deductions[] = ['name' => 'Loss of pay ('.$fmt($slip->lop_days).' days)', 'amount' => $slip->lop_amount]; }
    if ($slip->other_deduction > 0) { $deductions[] = ['name' => 'Other deductions', 'amount' => $slip->other_deduction]; }
    $rows = max(count($earnings), count($deductions));
@endphp

<table>
    <tr>
        <td style="width:60%; vertical-align:top">
            @if ($logo)<img src="{{ $logo }}" style="max-height:44px; max-width:170px; margin-bottom:6px"><br>@endif
            <strong style="font-size:14px">{{ $tenant->name }}</strong><br>
            @if ($tenant->address)<span class="muted">{!! nl2br(e($tenant->address)) !!}</span>@endif
        </td>
        <td class="right" style="vertical-align:top">
            <h1>PAYSLIP</h1>
            <div style="margin-top:6px"><strong>{{ $month }}</strong></div>
        </td>
    </tr>
</table>

<div class="box" style="margin-top:16px">
    <table class="grid">
        <tr>
            <td style="width:50%"><span class="muted">Employee</span> <strong>{{ $slip->user?->name }}</strong></td>
            <td><span class="muted">Employee code</span> {{ $profile?->employee_code ?? '—' }}</td>
        </tr>
        <tr>
            <td><span class="muted">Designation</span> {{ $profile?->designation ?? '—' }}</td>
            <td><span class="muted">Branch</span> {{ $slip->user?->branch?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td><span class="muted">Date of joining</span> {{ $profile?->date_of_joining?->format('d M Y') ?? '—' }}</td>
            <td><span class="muted">Bank / A/c</span> {{ $profile?->bank_name ?? '—' }} {{ $profile?->bank_account ? '· XXXX'.substr($profile->bank_account, -4) : '' }}</td>
        </tr>
        @if ($profile?->pan || $profile?->uan)
        <tr>
            <td><span class="muted">PAN</span> {{ $profile?->pan ?? '—' }}</td>
            <td><span class="muted">UAN</span> {{ $profile?->uan ?? '—' }}</td>
        </tr>
        @endif
    </table>
</div>

<div class="box" style="margin-top:10px">
    <h2>Attendance</h2>
    <table class="grid">
        <tr>
            <td>Days in month: <strong>{{ $slip->days_in_month }}</strong></td>
            <td>Working days: <strong>{{ $fmt($slip->working_days) }}</strong></td>
            <td>Present: <strong>{{ $fmt($slip->present_days) }}</strong></td>
            <td>Paid leave: <strong>{{ $fmt($slip->paid_leave_days) }}</strong></td>
        </tr>
        <tr>
            <td>Weekly offs: <strong>{{ $fmt($slip->weekly_offs) }}</strong></td>
            <td>Holidays: <strong>{{ $fmt($slip->holidays) }}</strong></td>
            <td>Unpaid leave: <strong>{{ $fmt($slip->unpaid_leave_days) }}</strong></td>
            <td>Absent: <strong>{{ $fmt($slip->absent_days) }}</strong></td>
        </tr>
    </table>
</div>

<table class="lines" style="margin-top:12px">
    <thead>
        <tr><th style="width:32%">Earnings</th><th class="right" style="width:18%">Amount</th><th style="width:32%">Deductions</th><th class="right" style="width:18%">Amount</th></tr>
    </thead>
    <tbody>
    @for ($i = 0; $i < $rows; $i++)
        <tr>
            <td>{{ $earnings[$i]['name'] ?? '' }}</td>
            <td class="right">{{ isset($earnings[$i]) ? $money($earnings[$i]['amount']) : '' }}</td>
            <td>{{ $deductions[$i]['name'] ?? '' }}</td>
            <td class="right">{{ isset($deductions[$i]) ? $money($deductions[$i]['amount']) : '' }}</td>
        </tr>
    @endfor
        <tr>
            <td><strong>Total earnings</strong></td>
            <td class="right"><strong>{{ $money(array_sum(array_column($earnings, 'amount'))) }}</strong></td>
            <td><strong>Total deductions</strong></td>
            <td class="right"><strong>{{ $money(array_sum(array_column($deductions, 'amount'))) }}</strong></td>
        </tr>
    </tbody>
</table>

<div class="net">
    <table><tr><td><strong>Net pay</strong></td><td class="right"><strong>{{ $money($slip->net_pay) }}</strong></td></tr></table>
</div>
@if ($slip->note)<p class="muted">Note: {{ $slip->note }}</p>@endif

<div class="footer">This is a computer-generated payslip and does not need a signature.</div>
</body>
</html>
