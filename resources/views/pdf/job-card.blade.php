<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Job card {{ $job->crm_call_id }}</title>
<style>
    @page { margin: 22px 26px 28px; size: A4 portrait; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { margin: 0; font-size: 9.2px; color: #000; line-height: 1.3; }
    table { width: 100%; border-collapse: collapse; }
    .center { text-align: center; }
    .right { text-align: right; }
    .muted { color: #444; }

    /* Letterhead */
    .company { font-size: 17px; font-weight: bold; letter-spacing: .03em; }
    .title { margin-top: 6px; border: 1.2px solid #000; background: #e6e6e6; text-align: center; font-size: 13px; font-weight: bold; letter-spacing: .35em; padding: 4px 0; }

    /* The form: every cell ruled */
    .form { border: 1.2px solid #000; border-top: 0; }
    .form td { border: 0.8px solid #000; padding: 3px 6px; vertical-align: top; }
    .form td.l { background: #f2f2f2; font-weight: bold; width: 17%; white-space: nowrap; }
    .form td.v { width: 33%; }
    .form tr.sec td { background: #d9d9d9; font-weight: bold; font-size: 8.6px; letter-spacing: .14em; padding: 3px 6px; }
    .form td.tall { height: 34px; }
    .box { display: inline-block; width: 8px; height: 8px; border: 0.9px solid #000; margin-right: 3px; vertical-align: -1px; }
    .tick { display: inline-block; width: 8px; height: 8px; border: 0.9px solid #000; margin-right: 3px; vertical-align: -1px; text-align: center; line-height: 7px; font-size: 7px; font-weight: bold; }
    .opt { margin-right: 12px; white-space: nowrap; }
    .details p { margin: 0 0 2px; } .details ul, .details ol { margin: 0 0 2px 14px; padding: 0; }

    .parts th { background: #f2f2f2; border: 0.8px solid #000; padding: 3px 5px; font-size: 8.4px; text-align: left; }
    .parts td { border: 0.8px solid #000; padding: 2px 5px; height: 13px; }

    .sign td { border: 0.8px solid #000; height: 46px; vertical-align: bottom; text-align: center; padding: 4px; font-size: 8.4px; }
    .terms { margin-top: 6px; font-size: 7.4px; color: #333; }
    .terms ol { margin: 2px 0 0 13px; padding: 0; }
    .foot { position: fixed; bottom: -16px; left: 0; right: 0; font-size: 7px; color: #555; }
</style>
</head>
<body>
@php
    $unit = $job->customerProduct;
    $brand = $unit?->product?->brand?->name ?? $unit?->brand?->name;
    $type = $unit?->category?->name ?? $unit?->product?->category?->name;
    $done = $job->visits->where('status', '!=', 'in_progress')->values();
    $last = $done->last();
    $fmt = fn ($d, $f = 'd-m-Y h:i A') => $d ? $d->timezone($tz)->format($f) : null;
    $callType = ['crm_call' => 'CRM call', 'walk_in' => 'Walk-in', 'referral' => 'Referral', 'portal' => 'Customer portal'][$job->call_type] ?? ucwords(str_replace('_', ' ', $job->call_type));
    $warranty = $unit?->warranty_type;
    $box = fn (bool $on) => $on ? '<span class="tick">&#10003;</span>' : '<span class="box"></span>';
    $parts = $done->flatMap(fn ($v) => $v->inventoryUsage)->values();
    $labour = (int) $done->sum('labour_charge');
    $spares = (int) $done->sum('spare_charge');
    $method = $last?->payment_method;
@endphp

{{-- ===== Letterhead ===== --}}
<table>
    <tr>
        <td style="width:80px; vertical-align:middle">@if ($logo)<img src="{{ $logo }}" style="max-height:54px; max-width:80px">@endif</td>
        <td class="center" style="vertical-align:middle">
            <div class="company">{{ $tenant->name }}</div>
            @if ($tenant->address)<div>{{ str_replace(["\r\n", "\n"], ', ', $tenant->address) }}</div>@endif
            <div>
                @if ($tenant->phone)Ph: {{ $tenant->phone }} @endif
                @if ($tenant->phone && $tenant->email) &nbsp;|&nbsp; @endif
                @if ($tenant->email)Email: {{ $tenant->email }} @endif
            </div>
            @if ($tenant->gstin)<div>GSTIN: {{ $tenant->gstin }}</div>@endif
        </td>
        <td style="width:80px; vertical-align:middle" class="right">
            <img src="{{ $qr }}" style="width:56px; height:56px"><br><span style="font-size:6.5px" class="muted">Scan to track</span>
        </td>
    </tr>
</table>
<div class="title">JOB CARD</div>

<table class="form">
    {{-- Job details --}}
    <tr>
        <td class="l">Job No.</td><td class="v"><b style="font-size:10.5px">{{ $job->crm_call_id }}</b></td>
        <td class="l">Date</td><td class="v">{{ $fmt($job->created_at) }}</td>
    </tr>
    <tr>
        <td class="l">Visit date</td><td class="v">{{ $fmt($job->scheduled_at) }}</td>
        <td class="l">Priority</td><td class="v">{{ ucfirst($job->priority) }}</td>
    </tr>
    <tr>
        <td class="l">Call type</td><td class="v">{{ $callType }}</td>
        <td class="l">Technician</td><td class="v">{{ $job->technician?->name }}{{ $job->technician?->phone ? ' ('.$job->technician->phone.')' : '' }}</td>
    </tr>

    {{-- Customer --}}
    <tr class="sec"><td colspan="4">CUSTOMER DETAILS</td></tr>
    <tr><td class="l">Customer name</td><td colspan="3"><b>{{ $job->customer?->name }}</b></td></tr>
    <tr>
        <td class="l">Mobile</td><td class="v">{{ $job->customer?->phone }}</td>
        <td class="l">Alt. mobile</td><td class="v">{{ $job->customer?->alt_phone }}</td>
    </tr>
    <tr>
        <td class="l">Address</td>
        <td colspan="3">{{ collect([$job->customer?->address, $job->customer?->city, $job->customer?->pincode])->filter()->implode(', ') }}</td>
    </tr>
    <tr>
        <td class="l">Email</td><td class="v">{{ $job->customer?->email }}</td>
        <td class="l">Area / branch</td><td class="v">{{ $job->serviceLocation?->name ?? $job->branch?->name }}</td>
    </tr>

    {{-- Product --}}
    <tr class="sec"><td colspan="4">PRODUCT DETAILS</td></tr>
    <tr>
        <td class="l">Brand</td><td class="v"><b>{{ $brand }}</b></td>
        <td class="l">Product type</td><td class="v">{{ $type }}{{ $job->product_category && $job->product_category !== $type ? " / ".$job->product_category : "" }}</td>
    </tr>
    <tr>
        <td class="l">Model No.</td><td class="v">{{ $unit?->model_no }}</td>
        <td class="l">Serial No.</td><td class="v">{{ $unit?->serial_no }}{{ $unit?->outdoor_serial_no ? ' / ODU '.$unit->outdoor_serial_no : '' }}</td>
    </tr>
    <tr>
        <td class="l">Purchase date</td><td class="v">{{ $unit?->purchase_date?->format('d-m-Y') }}</td>
        <td class="l">Warranty</td>
        <td class="v">
            <span class="opt">{!! $box($warranty === 'in_warranty' || $warranty === 'extended') !!}In warranty</span>
            <span class="opt">{!! $box($warranty === 'amc') !!}AMC</span>
            <span class="opt" style="margin-right:0">{!! $box($warranty === 'out_of_warranty') !!}Out</span>
        </td>
    </tr>

    {{-- Complaint --}}
    <tr class="sec"><td colspan="4">COMPLAINT</td></tr>
    <tr>
        <td class="l">Service type</td><td class="v"><b>{{ $job->complaintType?->name }}</b></td>
        <td class="l">Breakdown</td><td class="v">{{ $job->complaintSummary?->name }}</td>
    </tr>
    <tr>
        <td class="l">Job summary</td>
        <td colspan="3" class="details" style="height:26px">{!! $job->complaint_details_html ?: nl2br(e($job->complaint_details ?? '')) !!}</td>
    </tr>
    <tr>
        <td class="l">Customer Satisfaction Code</td>
        <td colspan="3" class="v">{{ $job->customer_satisfaction_code }}</td>
    </tr>

    {{-- Technician report --}}
    <tr class="sec"><td colspan="4">TECHNICIAN REPORT</td></tr>
    <tr>
        <td class="l">Fault found</td>
        <td colspan="3" class="tall">{{ $done->map(fn ($v) => $v->actionTaken?->name)->filter()->unique()->implode(', ') }}</td>
    </tr>
    <tr>
        <td class="l">Work done</td>
        <td colspan="3" class="tall">{{ $done->map(fn ($v) => $v->service_summary)->filter()->implode(' / ') }}</td>
    </tr>
    <tr>
        <td class="l">Part call</td>
        <td colspan="3">
            <span class="opt">{!! $box($parts->isNotEmpty()) !!}Part used</span>
            <span class="opt">{!! $box($done->isNotEmpty() && $parts->isEmpty()) !!}Part not used</span>
            <span class="opt">{!! $box(false) !!}Part to be ordered</span>
        </td>
    </tr>
    <tr>
        <td class="l">Time in</td><td class="v">{{ $fmt($last?->start_time) }}</td>
        <td class="l">Time out</td><td class="v">{{ $fmt($last?->end_time) }}</td>
    </tr>
</table>

{{-- Spare parts --}}
<table class="parts" style="margin-top:-0.8px">
    <tr><th style="width:6%" class="center">S.No</th><th>Spare part description</th><th style="width:14%">Part No.</th><th style="width:8%" class="right">Qty</th><th style="width:13%" class="right">Rate</th><th style="width:14%" class="right">Amount</th></tr>
    @for ($i = 0; $i < max(5, $parts->count()); $i++)
        @php $p = $parts[$i] ?? null; @endphp
        <tr>
            <td class="center">{{ $i + 1 }}</td>
            <td>{{ $p?->item?->name }}</td>
            <td>{{ $p?->item?->code }}</td>
            <td class="right">{{ $p ? rtrim(rtrim(number_format($p->quantity, 3), '0'), '.') : '' }}</td>
            <td class="right">{{ $p ? $money((int) $p->unit_price) : '' }}</td>
            <td class="right">{{ $p ? $money((int) $p->total_price) : '' }}</td>
        </tr>
    @endfor
</table>

{{-- Charges, payment, feedback --}}
<table class="form" style="margin-top:-0.8px">
    <tr class="sec"><td colspan="4">CHARGES &amp; PAYMENT</td></tr>
    <tr>
        <td class="l">Service charge</td><td class="v">{{ $done->isNotEmpty() ? $money($labour) : '' }}</td>
        <td class="l">Spare charges</td><td class="v">{{ $done->isNotEmpty() ? $money($spares) : '' }}</td>
    </tr>
    <tr>
        <td class="l">Total amount</td><td class="v"><b>{{ $done->isNotEmpty() ? $money($labour + $spares) : '' }}</b></td>
        <td class="l">Amount received</td><td class="v">{{ $job->invoice ? $money($job->invoice->paid_amount) : '' }}</td>
    </tr>
    <tr>
        <td class="l">Payment mode</td>
        <td colspan="3">
            <span class="opt">{!! $box($method === 'cash') !!}Cash</span>
            <span class="opt">{!! $box($method === 'upi') !!}UPI</span>
            <span class="opt">{!! $box($method === 'cheque') !!}Cheque</span>
            <span class="opt">{!! $box($method === 'bank_transfer') !!}Bank transfer</span>
            <span class="opt">{!! $box($method === 'credit') !!}Credit</span>
            @if ($job->invoice)<span class="muted"> &nbsp; Invoice: {{ $job->invoice->invoice_number }}</span>@endif
        </td>
    </tr>

    <tr class="sec"><td colspan="4">CUSTOMER FEEDBACK</td></tr>
    <tr>
        <td class="l">Service rating</td>
        <td colspan="3">
            <span class="opt"><span class="box"></span>Excellent</span>
            <span class="opt"><span class="box"></span>Good</span>
            <span class="opt"><span class="box"></span>Average</span>
            <span class="opt"><span class="box"></span>Poor</span>
        </td>
    </tr>
    <tr><td class="l">Remarks</td><td colspan="3" style="height:18px"></td></tr>
</table>

{{-- Signatures --}}
<table class="sign" style="margin-top:-0.8px">
    <tr>
        <td style="width:33.3%">Technician signature</td>
        <td style="width:33.4%">Customer signature<br><span class="muted" style="font-size:7px">Work done to my satisfaction</span></td>
        <td>For {{ $tenant->name }}<br><span class="muted" style="font-size:7px">Authorised signatory</span></td>
    </tr>
</table>

<div class="terms">
    <b>Terms &amp; conditions:</b>
    <ol>
        <li>Spare parts are charged extra as per actual use. Replaced parts become the property of the company unless the customer asks to keep them.</li>
        <li>Warranty on repaired / replaced parts is as per the manufacturer or as stated on the invoice.</li>
        <li>Payment is due on completion of service. Please collect an invoice / receipt for every payment.</li>
        <li>Please check the appliance before signing. The company is not responsible for faults unrelated to the work done.</li>
    </ol>
</div>

<div class="foot">
    <table><tr>
        <td>{{ $tenant->name }}{{ $tenant->phone ? ' · '.$tenant->phone : '' }}</td>
        <td class="right">Job {{ $job->crm_call_id }} · Printed {{ now($tz)->format('d-m-Y h:i A') }}</td>
    </tr></table>
</div>
</body>
</html>
