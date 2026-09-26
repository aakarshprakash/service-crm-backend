<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice {{ $invoice->invoice_number }}</title>
<style>
    @page { margin: 28px 32px; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { font-size: 10.5px; color: #1f2937; }
    .muted { color: #6b7280; }
    .right { text-align: right; }
    h1 { font-size: 20px; margin: 0; color: #1e3a8a; }
    h2 { font-size: 11px; margin: 0 0 6px; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .box { border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 12px; }
    .items th { background: #1e3a8a; color: #fff; text-align: left; padding: 7px 8px; font-size: 10px; }
    .items td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; }
    .totals td { padding: 4px 8px; }
    .totals .grand td { font-size: 13px; font-weight: bold; border-top: 2px solid #1e3a8a; padding-top: 8px; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 10px; font-weight: bold; }
    .paid { background: #dcfce7; color: #166534; } .partial { background: #fef3c7; color: #92400e; } .unpaid { background: #fee2e2; color: #991b1b; }
    .footer { margin-top: 28px; font-size: 9px; color: #9ca3af; text-align: center; }
</style>
</head>
<body>
@php
    $logo = null;
    if ($tenant->logo_path && \Illuminate\Support\Facades\Storage::disk('private')->exists($tenant->logo_path)) {
        $logo = 'data:image/png;base64,'.base64_encode(\Illuminate\Support\Facades\Storage::disk('private')->get($tenant->logo_path));
    }
    $tz = $tenant->timezone;
    $product = $invoice->job?->customerProduct;
@endphp

<table class="head">
    <tr>
        <td style="width:60%">
            @if ($logo)<img src="{{ $logo }}" style="max-height:48px; max-width:180px; margin-bottom:6px"><br>@endif
            <strong style="font-size:14px">{{ $tenant->name }}</strong><br>
            @if ($tenant->address)<span class="muted">{!! nl2br(e($tenant->address)) !!}</span><br>@endif
            @if ($tenant->phone)<span class="muted">Phone: {{ $tenant->phone }}</span><br>@endif
            @if ($tenant->email)<span class="muted">{{ $tenant->email }}</span><br>@endif
            @if ($tenant->gstin)<span class="muted">GSTIN: {{ $tenant->gstin }}</span>@endif
        </td>
        <td class="right">
            <h1>INVOICE</h1>
            <div style="margin-top:6px"><strong>{{ $invoice->invoice_number }}</strong></div>
            <div class="muted">Date: {{ $invoice->generated_at->timezone($tz)->format('d M Y') }}</div>
            <div class="muted">Call ID: {{ $invoice->job?->crm_call_id }}</div>
            <div style="margin-top:8px"><span class="badge {{ $invoice->payment_status }}">{{ strtoupper($invoice->payment_status) }}{{ $invoice->is_credit && $invoice->balance_amount > 0 ? ' · CREDIT' : '' }}</span></div>
        </td>
    </tr>
</table>

<table style="margin-top:18px">
    <tr>
        <td style="width:50%; padding-right:8px; vertical-align:top">
            <div class="box">
                <h2>Bill to</h2>
                <strong>{{ $invoice->customer?->name }}</strong><br>
                {{ $invoice->customer?->phone }}<br>
                @if ($invoice->customer?->address)<span class="muted">{{ $invoice->customer->address }}{{ $invoice->customer->city ? ', '.$invoice->customer->city : '' }} {{ $invoice->customer->pincode }}</span>@endif
            </div>
        </td>
        <td style="width:50%; padding-left:8px; vertical-align:top">
            <div class="box">
                <h2>Service details</h2>
                @if ($product?->product)<div>Product: {{ $product->product->brand?->name }} {{ $product->product->model_name }}</div>@endif
                @if ($product?->serial_no)<div>Serial no: {{ $product->serial_no }}</div>@endif
                @if ($invoice->job?->complaintType)<div>Complaint: {{ $invoice->job->complaintType->name }}</div>@endif
                @if ($invoice->branch)<div>Branch: {{ $invoice->branch->name }}</div>@endif
            </div>
        </td>
    </tr>
</table>

<table class="items" style="margin-top:18px">
    <thead>
        <tr><th style="width:18%">Date</th><th>Description</th><th class="right" style="width:12%">Qty</th><th class="right" style="width:16%">Rate</th><th class="right" style="width:16%">Amount</th></tr>
    </thead>
    <tbody>
    @foreach ($invoice->job?->visits ?? [] as $visit)
        @continue($visit->status === 'in_progress')
        @if ($visit->labour_charge > 0)
            <tr>
                <td>{{ $visit->start_time->timezone($tz)->format('d M Y') }}</td>
                <td>Service charge{{ $visit->actionTaken ? ' – '.$visit->actionTaken->name : '' }}<br><span class="muted">Technician: {{ $visit->technician?->name }}</span></td>
                <td class="right">1</td>
                <td class="right">{{ $money($visit->labour_charge) }}</td>
                <td class="right">{{ $money($visit->labour_charge) }}</td>
            </tr>
        @endif
        @foreach ($visit->inventoryUsage as $usage)
            <tr>
                <td>{{ $visit->start_time->timezone($tz)->format('d M Y') }}</td>
                <td>{{ $usage->item?->name }} <span class="muted">({{ $usage->item?->code }})</span></td>
                <td class="right">{{ rtrim(rtrim(number_format($usage->quantity, 3), '0'), '.') }} {{ $usage->item?->unit_of_measure }}</td>
                <td class="right">{{ $money($usage->unit_price) }}</td>
                <td class="right">{{ $money($usage->total_price) }}</td>
            </tr>
        @endforeach
        @php $usageTotal = (int) $visit->inventoryUsage->sum('total_price'); @endphp
        @if ($visit->spare_charge !== $usageTotal)
            <tr>
                <td>{{ $visit->start_time->timezone($tz)->format('d M Y') }}</td>
                <td>Spare charge adjustment</td>
                <td class="right">–</td><td class="right">–</td>
                <td class="right">{{ $money($visit->spare_charge - $usageTotal) }}</td>
            </tr>
        @endif
    @endforeach
    </tbody>
</table>

<table style="margin-top:12px">
    <tr>
        <td style="width:55%; vertical-align:top">
            @if ($invoice->payments->isNotEmpty())
                <h2 style="margin-top:8px">Payments received</h2>
                @foreach ($invoice->payments as $payment)
                    <div class="muted">{{ $payment->paid_at?->timezone($tz)->format('d M Y') }} · {{ ucwords(str_replace('_', ' ', $payment->method)) }} · {{ $money($payment->amount) }}{{ $payment->receipt_number ? ' · '.$payment->receipt_number : '' }}</div>
                @endforeach
            @endif
        </td>
        <td style="width:45%">
            <table class="totals">
                <tr><td>Total service charge</td><td class="right">{{ $money($invoice->total_service_charge) }}</td></tr>
                <tr><td>Total spare charge</td><td class="right">{{ $money($invoice->total_spare_charge) }}</td></tr>
                <tr class="grand"><td>Total</td><td class="right">{{ $money($invoice->total_amount) }}</td></tr>
                <tr><td>Paid</td><td class="right">{{ $money($invoice->paid_amount) }}</td></tr>
                <tr><td><strong>Balance due</strong></td><td class="right"><strong>{{ $money($invoice->balance_amount) }}</strong></td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="footer">This is a computer-generated invoice. Thank you for choosing {{ $tenant->name }}.</div>
</body>
</html>
