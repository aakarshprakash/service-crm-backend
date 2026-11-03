<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }} {{ $invoice->invoice_number }}</title>
<style>
    @page { margin: 0; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { font-size: 9.5px; color: #1e293b; margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    .wrap { padding: 0 30px; }
    .muted { color: #64748b; }
    .right { text-align: right; }
    .center { text-align: center; }
    .band { background: #0f3d63; color: #fff; padding: 22px 30px 18px; }
    .band .muted { color: #c7d7e6; }
    .band h1 { font-size: 22px; margin: 0; letter-spacing: .14em; }
    .accent { color: #0f6cbd; }
    .label { font-size: 7.5px; text-transform: uppercase; letter-spacing: .1em; color: #64748b; margin-bottom: 2px; }
    .card { background: #f1f5f9; border-radius: 6px; padding: 9px 11px; }
    .meta td { padding: 2px 0; }
    .items th { background: #0f3d63; color: #fff; padding: 6px 5px; font-size: 8.5px; text-align: left; }
    .items td { padding: 6px 5px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    .items tr.alt td { background: #f8fafc; }
    .totals td { padding: 3px 0; }
    .grand td { font-size: 13px; font-weight: bold; color: #0f3d63; border-top: 2px solid #0f6cbd; padding-top: 6px; }
    .hsn th { font-size: 7.5px; text-transform: uppercase; color: #64748b; text-align: left; padding: 3px 4px; border-bottom: 1px solid #cbd5e1; }
    .hsn td { padding: 3px 4px; font-size: 8.5px; border-bottom: 1px solid #f1f5f9; }
    .pill { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 8.5px; font-weight: bold; }
    .paid { background: #dcfce7; color: #166534; } .partial { background: #fef3c7; color: #92400e; } .unpaid { background: #fee2e2; color: #991b1b; }
    .foot { position: fixed; bottom: 0; left: 0; right: 0; background: #0f3d63; color: #c7d7e6; text-align: center; font-size: 8px; padding: 7px 30px; }
</style>
</head>
<body>
<div class="band">
    <table>
        <tr>
            <td style="vertical-align:top">
                <table style="width:auto"><tr>
                    @if ($logo)<td style="padding-right:10px; vertical-align:top"><img src="{{ $logo }}" style="max-height:52px; max-width:90px; background:#fff; border-radius:4px; padding:3px"></td>@endif
                    <td style="vertical-align:top">
                        <div style="font-size:15px; font-weight:bold">{{ $tenant->name }}</div>
                        @if ($tenant->address)<div class="muted">{!! nl2br(e($tenant->address)) !!}</div>@endif
                        @if ($companyState)<div class="muted">{{ $companyState }}</div>@endif
                        @if ($tenant->phone || $tenant->email)<div class="muted">{{ $tenant->phone }}{{ $tenant->phone && $tenant->email ? '  ·  ' : '' }}{{ $tenant->email }}</div>@endif
                    </td>
                </tr></table>
            </td>
            <td class="right" style="vertical-align:top; width:42%">
                <h1>{{ $title }}</h1>
                <div style="font-size:12px; margin-top:4px; font-weight:bold">{{ $invoice->invoice_number }}</div>
                <div class="muted">{{ $invoice->generated_at->timezone($tz)->format('d M Y') }}</div>
                @if ($invoice->is_gst && $tenant->gstin)<div style="margin-top:6px">GSTIN {{ $tenant->gstin }}</div>@endif
                @if ($tenant->pan)<div class="muted">PAN {{ $tenant->pan }}</div>@endif
            </td>
        </tr>
    </table>
</div>

<div class="wrap" style="margin-top:16px">
    <table>
        <tr>
            <td style="width:48%; vertical-align:top; padding-right:8px">
                <div class="card">
                    <div class="label">Billed to</div>
                    <div style="font-size:11.5px; font-weight:bold">{{ $invoice->customer?->name }}</div>
                    <div>{{ $invoice->customer?->phone }}</div>
                    @if ($invoice->customer?->address)<div class="muted">{{ $invoice->customer->address }}{{ $invoice->customer->city ? ', '.$invoice->customer->city : '' }} {{ $invoice->customer->pincode }}</div>@endif
                    @if ($customerState)<div class="muted">{{ $customerState }}</div>@endif
                    @if ($invoice->customer_gstin)<div style="margin-top:2px"><strong>GSTIN</strong> {{ $invoice->customer_gstin }}</div>@endif
                </div>
            </td>
            <td style="width:52%; vertical-align:top; padding-left:8px">
                <div class="card">
                    <table class="meta">
                        @if ($invoice->job)<tr><td class="label">Call ID</td><td class="right">{{ $invoice->job->crm_call_id }}</td></tr>@else<tr><td class="label">Type</td><td class="right">Counter sale</td></tr>@endif
                        @php $product = $invoice->job?->customerProduct; @endphp
                        @if ($product?->model_no || $product?->product?->brand || $product?->brand)<tr><td class="label">Product</td><td class="right">{{ $product->product?->brand?->name ?? $product->brand?->name }} {{ $product->model_no }}</td></tr>@endif
                        @if ($product?->serial_no)<tr><td class="label">Serial no</td><td class="right">{{ $product->serial_no }}</td></tr>@endif
                        @if ($invoice->job?->complaintType)<tr><td class="label">Service type</td><td class="right">{{ $invoice->job->complaintType->name }}</td></tr>@endif
                        @if ($invoice->is_gst)<tr><td class="label">Place of supply</td><td class="right">{{ $invoice->place_of_supply ?? '—' }}</td></tr>@endif
                        <tr><td class="label">Status</td><td class="right"><span class="pill {{ $invoice->payment_status }}">{{ strtoupper($invoice->payment_status) }}{{ $invoice->is_credit && $invoice->balance_amount > 0 ? ' · CREDIT' : '' }}</span></td></tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <table class="items" style="margin-top:16px">
        <thead>
            <tr>
                <th style="width:4%">#</th>
                <th>Item / service</th>
                @if ($invoice->is_gst)<th style="width:9%">HSN/SAC</th>@endif
                <th class="right" style="width:7%">Qty</th>
                <th class="right" style="width:11%">Rate</th>
                @if ($discount > 0)<th class="right" style="width:9%">Disc.</th>@endif
                @if ($invoice->is_gst)
                    <th class="right" style="width:11%">Taxable</th>
                    <th class="right" style="width:7%">GST</th>
                    <th class="right" style="width:10%">Tax</th>
                @endif
                <th class="right" style="width:12%">Amount</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($lines as $i => $l)
            <tr class="{{ $i % 2 ? 'alt' : '' }}">
                <td class="muted">{{ $i + 1 }}</td>
                <td>{{ $l['description'] }}</td>
                @if ($invoice->is_gst)<td class="muted">{{ $l['hsn'] ?? '—' }}</td>@endif
                <td class="right">{{ $qty($l['qty']) }}</td>
                <td class="right">{{ $money($invoice->is_gst ? $l['unit_taxable'] : ((float) $l['qty'] > 0 ? (int) round($l['gross'] / (float) $l['qty']) : $l['gross'])) }}</td>
                @if ($discount > 0)<td class="right">{{ $l['discount'] ? $money($l['discount']) : '—' }}</td>@endif
                @if ($invoice->is_gst)
                    <td class="right">{{ $money($l['taxable']) }}</td>
                    <td class="right">{{ rtrim(rtrim(number_format($l['gst_rate'], 2), '0'), '.') }}%</td>
                    <td class="right">{{ $money($l['tax']) }}</td>
                @endif
                <td class="right"><strong>{{ $money($l['net']) }}</strong></td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table style="margin-top:14px">
        <tr>
            <td style="width:56%; vertical-align:top; padding-right:16px">
                @if ($summary)
                    <div class="label">GST summary</div>
                    <table class="hsn">
                        <tr><th>HSN/SAC</th><th>Rate</th><th class="right">Taxable</th>@if ($interState)<th class="right">IGST</th>@else<th class="right">CGST</th><th class="right">SGST</th>@endif</tr>
                        @foreach ($summary as $s)
                            <tr>
                                <td>{{ $s['hsn'] ?? '—' }}</td>
                                <td>{{ rtrim(rtrim(number_format($s['gst_rate'], 2), '0'), '.') }}%</td>
                                <td class="right">{{ $money($s['taxable']) }}</td>
                                @if ($interState)<td class="right">{{ $money($s['igst']) }}</td>@else<td class="right">{{ $money($s['cgst']) }}</td><td class="right">{{ $money($s['sgst']) }}</td>@endif
                            </tr>
                        @endforeach
                    </table>
                @endif

                <div class="label" style="margin-top:10px">Amount in words</div>
                <div style="font-weight:bold">{{ $words }}</div>

                @if ($invoice->payments->isNotEmpty())
                    <div class="label" style="margin-top:10px">Payments received</div>
                    @foreach ($invoice->payments as $payment)
                        <div class="muted">{{ $payment->paid_at?->timezone($tz)->format('d M Y') }} · {{ ucwords(str_replace('_', ' ', $payment->method)) }} · {{ $money($payment->amount) }}{{ $payment->receipt_number ? ' · '.$payment->receipt_number : '' }}</div>
                    @endforeach
                @endif
            </td>
            <td style="vertical-align:top">
                <table class="totals">
                    @if ($discount > 0)
                        <tr><td class="muted">Gross amount</td><td class="right">{{ $money($gross) }}</td></tr>
                        <tr><td class="muted">Discount</td><td class="right">- {{ $money($discount) }}</td></tr>
                    @endif
                    @if ($invoice->is_gst)
                        <tr><td class="muted">Taxable value</td><td class="right">{{ $money($invoice->taxable_amount) }}</td></tr>
                        @if ($interState)
                            <tr><td class="muted">IGST</td><td class="right">{{ $money($invoice->igst_amount) }}</td></tr>
                        @else
                            <tr><td class="muted">CGST</td><td class="right">{{ $money($invoice->cgst_amount) }}</td></tr>
                            <tr><td class="muted">SGST</td><td class="right">{{ $money($invoice->sgst_amount) }}</td></tr>
                        @endif
                    @endif
                    <tr class="grand"><td>Total</td><td class="right">{{ $money($invoice->total_amount) }}</td></tr>
                    <tr><td class="muted">Paid</td><td class="right">{{ $money($invoice->paid_amount) }}</td></tr>
                    <tr><td><strong class="accent">Balance due</strong></td><td class="right"><strong class="accent">{{ $money($invoice->balance_amount) }}</strong></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table style="margin-top:18px">
        <tr>
            <td style="vertical-align:bottom; width:56%">
                @if ($upiQr)
                    <table style="width:auto"><tr>
                        <td style="padding:0"><img src="{{ $upiQr }}" alt="UPI QR" style="width:92px; height:92px"></td>
                        <td style="padding-left:10px; vertical-align:middle">
                            <div style="font-weight:bold" class="accent">Scan to pay {{ $money($upi['amount']) }}</div>
                            <div class="muted">Any UPI app · {{ $upi['vpa'] }}</div>
                            <div class="muted">{{ $upi['payee_name'] }}</div>
                        </td>
                    </tr></table>
                @endif
                @if ($signature)
                    <div style="margin-top:8px">
                        <img src="{{ $signature['src'] }}" alt="Customer signature" style="height:42px; max-width:180px"><br>
                        <span class="muted">Customer signature{{ $signature['name'] ? ' – '.$signature['name'] : '' }} · {{ $signature['at']->timezone($tz)->format('d M Y, h:i A') }}</span>
                    </div>
                @endif
            </td>
            <td class="center" style="vertical-align:bottom">
                <div style="font-weight:bold">For {{ $tenant->name }}</div>
                <div style="height:44px"></div>
                <div style="border-top:1px solid #cbd5e1; padding-top:3px" class="muted">Authorised signatory</div>
            </td>
        </tr>
    </table>
    <div class="muted" style="margin-top:12px; font-size:8px">@if ($invoice->is_gst)Prices are inclusive of GST. @endif This is a computer-generated invoice and does not require a physical signature.</div>
</div>
<div class="foot">Thank you for choosing {{ $tenant->name }}{{ $tenant->phone ? ' · '.$tenant->phone : '' }}</div>
</body>
</html>
