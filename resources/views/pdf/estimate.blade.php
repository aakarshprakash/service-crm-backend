<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Estimate {{ $estimate->estimate_number }}</title>
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
    .terms { white-space: pre-line; line-height: 1.5; }
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
                <h1>ESTIMATE</h1>
                <div style="font-size:12px; margin-top:4px; font-weight:bold">{{ $estimate->estimate_number }}</div>
                <div class="muted">{{ $estimate->estimate_date->format('d M Y') }}</div>
                @if ($estimate->is_gst && $tenant->gstin)<div style="margin-top:6px">GSTIN {{ $tenant->gstin }}</div>@endif
            </td>
        </tr>
    </table>
</div>

<div class="wrap" style="margin-top:16px">
    <table>
        <tr>
            <td style="width:48%; vertical-align:top; padding-right:8px">
                <div class="card">
                    <div class="label">Estimate for</div>
                    <div style="font-size:11.5px; font-weight:bold">{{ $estimate->customer?->name }}</div>
                    <div>{{ $estimate->customer?->phone }}</div>
                    @if ($estimate->customer?->address)<div class="muted">{{ $estimate->customer->address }}{{ $estimate->customer->city ? ', '.$estimate->customer->city : '' }} {{ $estimate->customer->pincode }}</div>@endif
                    @if ($customerState)<div class="muted">{{ $customerState }}</div>@endif
                    @if ($estimate->customer_gstin)<div style="margin-top:2px"><strong>GSTIN</strong> {{ $estimate->customer_gstin }}</div>@endif
                </div>
            </td>
            <td style="width:52%; vertical-align:top; padding-left:8px">
                <div class="card">
                    <table class="meta">
                        @if ($estimate->valid_until)<tr><td class="label">Valid until</td><td class="right"><strong>{{ $estimate->valid_until->format('d M Y') }}</strong></td></tr>@endif
                        @if ($estimate->job)<tr><td class="label">Call ID</td><td class="right">{{ $estimate->job->crm_call_id }}</td></tr>@endif
                        @php $product = $estimate->customerProduct; @endphp
                        @if ($product && ($product->model_no || $product->product?->brand || $product->brand))<tr><td class="label">Product</td><td class="right">{{ $product->product?->brand?->name ?? $product->brand?->name }} {{ $product->category?->name }} {{ $product->model_no }}</td></tr>@endif
                        @if ($product?->serial_no)<tr><td class="label">Serial no</td><td class="right">{{ $product->serial_no }}</td></tr>@endif
                        @if ($estimate->is_gst)<tr><td class="label">Place of supply</td><td class="right">{{ $estimate->place_of_supply ?? '—' }}</td></tr>@endif
                        @if ($estimate->creator)<tr><td class="label">Prepared by</td><td class="right">{{ $estimate->creator->name }}</td></tr>@endif
                    </table>
                </div>
            </td>
        </tr>
    </table>

    @if ($estimate->subject)
        <div style="margin-top:14px; font-size:11.5px; font-weight:bold" class="accent">{{ $estimate->subject }}</div>
    @endif

    <table class="items" style="margin-top:{{ $estimate->subject ? '8px' : '16px' }}">
        <thead>
            <tr>
                <th style="width:4%">#</th>
                <th>Item / service</th>
                @if ($estimate->is_gst)<th style="width:9%">HSN/SAC</th>@endif
                <th class="right" style="width:7%">Qty</th>
                <th class="right" style="width:11%">Rate</th>
                @if ($discount > 0)<th class="right" style="width:9%">Disc.</th>@endif
                @if ($estimate->is_gst)
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
                @if ($estimate->is_gst)<td class="muted">{{ $l['hsn'] ?? '—' }}</td>@endif
                <td class="right">{{ $qty($l['qty']) }}{{ ! empty($l['uom']) ? ' '.$l['uom'] : '' }}</td>
                <td class="right">{{ $money($estimate->is_gst ? $l['unit_taxable'] : ((float) $l['qty'] > 0 ? (int) round($l['gross'] / (float) $l['qty']) : $l['gross'])) }}</td>
                @if ($discount > 0)<td class="right">{{ $l['discount'] ? $money($l['discount']) : '—' }}</td>@endif
                @if ($estimate->is_gst)
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

                @if ($estimate->notes)
                    <div class="label" style="margin-top:10px">Notes</div>
                    <div class="terms">{{ $estimate->notes }}</div>
                @endif
            </td>
            <td style="vertical-align:top">
                <table class="totals">
                    @if ($discount > 0)
                        <tr><td class="muted">Gross amount</td><td class="right">{{ $money($gross) }}</td></tr>
                        <tr><td class="muted">Discount</td><td class="right">- {{ $money($discount) }}</td></tr>
                    @endif
                    @if ($estimate->is_gst)
                        <tr><td class="muted">Taxable value</td><td class="right">{{ $money($estimate->taxable_amount) }}</td></tr>
                        @if ($interState)
                            <tr><td class="muted">IGST</td><td class="right">{{ $money($estimate->igst_amount) }}</td></tr>
                        @else
                            <tr><td class="muted">CGST</td><td class="right">{{ $money($estimate->cgst_amount) }}</td></tr>
                            <tr><td class="muted">SGST</td><td class="right">{{ $money($estimate->sgst_amount) }}</td></tr>
                        @endif
                    @endif
                    <tr class="grand"><td>Estimated total</td><td class="right">{{ $money($estimate->total_amount) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table style="margin-top:18px">
        <tr>
            <td style="vertical-align:top; width:56%; padding-right:16px">
                @if ($estimate->terms)
                    <div class="label">Terms</div>
                    <div class="terms muted">{{ $estimate->terms }}</div>
                @endif
            </td>
            <td class="center" style="vertical-align:bottom">
                <div style="font-weight:bold">For {{ $tenant->name }}</div>
                <div style="height:44px"></div>
                <div style="border-top:1px solid #cbd5e1; padding-top:3px" class="muted">Authorised signatory</div>
            </td>
        </tr>
    </table>
    <div class="muted" style="margin-top:12px; font-size:8px">This is an estimate, not a tax invoice. The final bill is made for the work actually done and the parts actually used.@if ($estimate->is_gst) Prices are inclusive of GST.@endif</div>
</div>
<div class="foot">{{ $tenant->name }}{{ $tenant->phone ? ' · '.$tenant->phone : '' }}{{ $tenant->email ? ' · '.$tenant->email : '' }}</div>
</body>
</html>
