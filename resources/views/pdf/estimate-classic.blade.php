<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Estimate {{ $estimate->estimate_number }}</title>
<style>
    @page { margin: 30px 34px 40px; }
    * { font-family: Helvetica, Arial, sans-serif; }
    body { font-size: 9.5px; color: #111; line-height: 1.35; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }
    .frame { border: 1px solid #222; }
    .right { text-align: right; }
    .center { text-align: center; }
    .bt { border-top: 1px solid #222; }
    .bl { border-left: 1px solid #222; }
    .pad { padding: 6px 10px; }
    .muted { color: #555; }
    .company { font-size: 17px; font-weight: bold; letter-spacing: .02em; }
    .title { text-align: center; font-size: 13px; font-weight: bold; letter-spacing: .3em; padding: 4px 0; background: #e9ecef; }
    .caption { font-size: 8px; font-weight: bold; color: #444; letter-spacing: .06em; margin-bottom: 3px; }
    .party { font-size: 11px; font-weight: bold; }
    .kv td { padding: 1.5px 0; }
    .kv td.k { width: 84px; color: #333; }
    .kv td.c { width: 10px; }
    .items thead { display: table-header-group; }
    .items tr { page-break-inside: avoid; }
    .items th { background: #e9ecef; border-left: 1px solid #222; border-bottom: 1px solid #222; padding: 5px 3px; font-size: 8.3px; text-align: center; vertical-align: middle; }
    .items td { border-left: 1px solid #222; padding: 4px 4px; font-size: 8.8px; }
    .items th:first-child, .items td:first-child { border-left: 0; }
    .items .sum td { border-top: 1px solid #222; font-weight: bold; background: #f6f7f8; }
    .hsn th { background: #f6f7f8; border-left: 1px solid #222; border-bottom: 1px solid #222; padding: 3px; font-size: 8px; text-align: center; }
    .hsn td { border-left: 1px solid #222; padding: 3px 4px; font-size: 8.5px; text-align: right; }
    .hsn th:first-child, .hsn td:first-child { border-left: 0; text-align: left; }
    .hsn .sum td { border-top: 1px solid #222; font-weight: bold; }
    .totals td { padding: 2.5px 10px; }
    .grand td { font-size: 11px; font-weight: bold; border-top: 1px solid #222; padding-top: 5px; padding-bottom: 5px; background: #e9ecef; }
    .terms { white-space: pre-line; }
    .small { font-size: 8px; }
</style>
</head>
<body>
@php
    $customer = $estimate->customer;
    $product = $estimate->customerProduct;
    $partyAddress = array_filter([$customer?->address, trim(($customer?->city ?? '').($customer?->pincode ? ' - '.$customer->pincode : ''))]);
    $gst = (bool) $estimate->is_gst;
    $withDiscount = $discount > 0;
    $tax = $estimate->cgst_amount + $estimate->sgst_amount + $estimate->igst_amount;
    // The rate as it was typed: before GST when GST is added on top, including GST when it is inside the price.
    $unitPrice = fn ($l) => (float) $l['qty'] > 0 ? (int) round($l['gross'] / (float) $l['qty']) : $l['gross'];
    // S.No, description, qty, unit, rate, amount; with GST also HSN, taxable, GST % and GST, and Disc. when there is one.
    $columns = $gst ? 10 + ($withDiscount ? 1 : 0) : 6;
    // Short estimates still get columns that run down the page, as on a printed quotation.
    $fillHeight = max(10, 190 - count($lines) * 26);
@endphp
<div class="frame">
    {{-- Letterhead --}}
    <table>
        <tr>
            @if ($logo)
                <td style="width:120px; padding:8px 10px; vertical-align:middle" class="center"><img src="{{ $logo }}" style="max-height:66px; max-width:110px"></td>
            @endif
            <td class="{{ $logo ? '' : 'center' }}" style="padding:8px 10px">
                <div class="company">{{ strtoupper($tenant->name) }}</div>
                @if ($tenant->address)<div>{!! nl2br(e($tenant->address)) !!}</div>@endif
                <div>
                    @if ($tenant->phone)Phone: {{ $tenant->phone }}@endif
                    @if ($tenant->phone && $tenant->email) &nbsp;|&nbsp; @endif
                    @if ($tenant->email)Email: {{ $tenant->email }}@endif
                </div>
                <div>
                    @if ($gst && $tenant->gstin)<strong>GSTIN: {{ $tenant->gstin }}</strong>@endif
                    @if ($gst && $tenant->gstin && $companyStateName) &nbsp;|&nbsp; @endif
                    @if ($companyStateName)State: {{ $companyStateName }}@if ($companyStateCode) ({{ $companyStateCode }})@endif @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="title bt">ESTIMATE</div>

    {{-- Customer and estimate details --}}
    <table class="bt">
        <tr>
            <td class="pad" style="width:52%">
                <div class="caption">ESTIMATE FOR</div>
                <div class="party">{{ $customer?->name }}</div>
                @foreach ($partyAddress as $line)<div>{{ $line }}</div>@endforeach
                @if ($customer?->phone)<div>Phone: {{ $customer->phone }}</div>@endif
                @if ($customerStateName)<div>State: {{ $customerStateName }}@if ($customerStateCode) ({{ $customerStateCode }})@endif</div>@endif
                @if ($gst)<div>GSTIN: {{ $estimate->customer_gstin ?: 'Unregistered' }}</div>@endif
            </td>
            <td class="pad bl">
                <table class="kv">
                    <tr><td class="k">Estimate No</td><td class="c">:</td><td><strong>{{ $estimate->estimate_number }}</strong></td></tr>
                    <tr><td class="k">Date</td><td class="c">:</td><td>{{ $estimate->estimate_date->format('d-m-Y') }}</td></tr>
                    @if ($estimate->valid_until)<tr><td class="k">Valid until</td><td class="c">:</td><td><strong>{{ $estimate->valid_until->format('d-m-Y') }}</strong></td></tr>@endif
                    @if ($gst)<tr><td class="k">Place of supply</td><td class="c">:</td><td>{{ $estimate->place_of_supply ?? '-' }}</td></tr>@endif
                    @if ($estimate->job)<tr><td class="k">Call ID</td><td class="c">:</td><td>{{ $estimate->job->crm_call_id }}</td></tr>@endif
                    @if ($product && ($product->model_no || $product->product?->brand || $product->brand))
                        <tr><td class="k">Product</td><td class="c">:</td><td>{{ $product->product?->brand?->name ?? $product->brand?->name }} {{ $product->category?->name }} {{ $product->model_no }}</td></tr>
                    @endif
                    @if ($product?->serial_no)<tr><td class="k">Serial No</td><td class="c">:</td><td>{{ $product->serial_no }}</td></tr>@endif
                    @if ($estimate->creator)<tr><td class="k">Prepared by</td><td class="c">:</td><td>{{ $estimate->creator->name }}</td></tr>@endif
                </table>
            </td>
        </tr>
    </table>

    @if ($estimate->subject)
        <div class="bt pad"><strong>Subject:</strong> {{ $estimate->subject }}</div>
    @endif

    {{-- Items --}}
    <table class="items bt">
        <thead>
            <tr>
                <th style="width:4%">S.No</th>
                <th>Description of Goods / Services</th>
                @if ($gst)<th style="width:8%">HSN / SAC</th>@endif
                <th style="width:5%">Qty</th>
                <th style="width:5.5%">Unit</th>
                <th style="width:{{ $gst ? '9.5' : '12' }}%">{{ $gst && ! $taxAdded ? 'Rate incl. GST' : 'Rate' }}<br>(Rs.)</th>
                @if ($gst && $withDiscount)<th style="width:7.5%">Disc.<br>(Rs.)</th>@endif
                @if ($gst)
                    <th style="width:10%">Taxable<br>(Rs.)</th>
                    <th style="width:5.5%">GST<br>%</th>
                    <th style="width:9%">GST<br>(Rs.)</th>
                @endif
                <th style="width:{{ $gst ? '10.5' : '13' }}%">Amount<br>(Rs.)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $i => $l)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td>
                        <div style="{{ ! empty($l['details']) ? 'font-weight:bold' : '' }}">{!! nl2br(e($l['description'])) !!}</div>
                        @if (! empty($l['details']))<div class="muted" style="margin-top:1px">{!! nl2br(e($l['details'])) !!}</div>@endif
                    </td>
                    @if ($gst)<td class="center">{{ $l['hsn'] ?? '' }}</td>@endif
                    <td class="center">{{ $qty($l['qty']) }}</td>
                    <td class="center">{{ $l['uom'] ?: '-' }}</td>
                    <td class="right">{{ $amount($unitPrice($l)) }}</td>
                    @if ($gst && $withDiscount)<td class="right">{{ $l['discount'] ? $amount($l['discount']) : '-' }}</td>@endif
                    @if ($gst)
                        <td class="right">{{ $amount($l['taxable']) }}</td>
                        <td class="center">{{ $rate($l['gst_rate']) }}</td>
                        <td class="right">{{ $amount($l['tax']) }}</td>
                    @endif
                    <td class="right"><strong>{{ $amount($gst ? $l['net'] : $l['gross']) }}</strong></td>
                </tr>
            @endforeach
            <tr>@for ($c = 0; $c < $columns; $c++)<td style="height:{{ $fillHeight }}px"></td>@endfor</tr>
            <tr class="sum">
                <td></td>
                <td class="right">Total</td>
                @if ($gst)<td></td>@endif
                <td></td><td></td><td></td>
                @if ($gst && $withDiscount)<td class="right">{{ $amount($discount) }}</td>@endif
                @if ($gst)
                    <td class="right">{{ $amount($estimate->taxable_amount) }}</td>
                    <td></td>
                    <td class="right">{{ $amount($tax) }}</td>
                @endif
                <td class="right">{{ $amount($gst ? $estimate->total_amount : $gross) }}</td>
            </tr>
        </tbody>
    </table>

    {{-- GST by HSN / SAC --}}
    @if ($gst && count($summary))
        <table class="hsn bt">
            <thead>
                <tr>
                    <th style="width:18%">HSN / SAC</th>
                    <th>Taxable value (Rs.)</th>
                    @if ($interState)
                        <th style="width:12%">IGST %</th>
                        <th style="width:16%">IGST (Rs.)</th>
                    @else
                        <th style="width:9%">CGST %</th>
                        <th style="width:13%">CGST (Rs.)</th>
                        <th style="width:9%">SGST %</th>
                        <th style="width:13%">SGST (Rs.)</th>
                    @endif
                    <th style="width:15%">Total GST (Rs.)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($summary as $s)
                    <tr>
                        <td>{{ $s['hsn'] ?? '-' }}</td>
                        <td>{{ $amount($s['taxable']) }}</td>
                        @if ($interState)
                            <td>{{ $rate($s['gst_rate']) }}</td>
                            <td>{{ $amount($s['igst']) }}</td>
                        @else
                            <td>{{ $rate($s['gst_rate'] / 2) }}</td>
                            <td>{{ $amount($s['cgst']) }}</td>
                            <td>{{ $rate($s['gst_rate'] / 2) }}</td>
                            <td>{{ $amount($s['sgst']) }}</td>
                        @endif
                        <td>{{ $amount($s['tax']) }}</td>
                    </tr>
                @endforeach
                <tr class="sum">
                    <td>Total</td>
                    <td>{{ $amount($estimate->taxable_amount) }}</td>
                    @if ($interState)
                        <td></td><td>{{ $amount($estimate->igst_amount) }}</td>
                    @else
                        <td></td><td>{{ $amount($estimate->cgst_amount) }}</td>
                        <td></td><td>{{ $amount($estimate->sgst_amount) }}</td>
                    @endif
                    <td>{{ $amount($tax) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    {{-- Amount in words, notes and totals --}}
    <table class="bt">
        <tr>
            <td class="pad" style="width:56%">
                <div class="caption">AMOUNT IN WORDS</div>
                <div><strong>{{ $words }}</strong></div>
                @if ($estimate->notes)
                    <div class="caption" style="margin-top:8px">NOTE</div>
                    <div class="terms">{{ $estimate->notes }}</div>
                @endif
            </td>
            <td class="bl" style="padding:3px 0">
                <table class="totals">
                    @if ($gst)
                        @if ($taxAdded && $withDiscount)
                            <tr><td>Total</td><td class="right">{{ $amount($gross) }}</td></tr>
                            <tr><td>Less: Discount</td><td class="right">- {{ $amount($discount) }}</td></tr>
                        @endif
                        <tr><td><strong>Taxable value</strong></td><td class="right"><strong>{{ $amount($estimate->taxable_amount) }}</strong></td></tr>
                        @foreach ($taxRates as $t)
                            @if ($interState)
                                <tr><td>Add: IGST @ {{ $t['rate'] }}%</td><td class="right">{{ $amount($t['igst']) }}</td></tr>
                            @else
                                <tr><td>Add: CGST @ {{ $t['half'] }}%</td><td class="right">{{ $amount($t['cgst']) }}</td></tr>
                                <tr><td>Add: SGST @ {{ $t['half'] }}%</td><td class="right">{{ $amount($t['sgst']) }}</td></tr>
                            @endif
                        @endforeach
                        <tr class="grand"><td>Total incl. GST (Rs.)</td><td class="right">{{ $amount($estimate->total_amount) }}</td></tr>
                    @else
                        <tr><td>Total</td><td class="right">{{ $amount($gross) }}</td></tr>
                        @if ($withDiscount)<tr><td>Less: Discount</td><td class="right">- {{ $amount($discount) }}</td></tr>@endif
                        <tr class="grand"><td>Estimated total (Rs.)</td><td class="right">{{ $amount($estimate->total_amount) }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    {{-- Terms and signatory --}}
    <table class="bt" style="page-break-inside:avoid">
        <tr>
            <td class="pad" style="width:56%">
                @if ($estimate->terms)
                    <div class="caption">TERMS &amp; CONDITIONS</div>
                    <div class="terms">{{ $estimate->terms }}</div>
                @endif
            </td>
            <td class="bl right" style="padding:6px 10px">
                <div>For <strong>{{ strtoupper($tenant->name) }}</strong></div>
                <div style="height:58px; padding-top:4px">
                    @if ($signature)<img src="{{ $signature }}" style="max-height:50px; max-width:150px; vertical-align:bottom">@endif
                    @if ($stamp)<img src="{{ $stamp }}" style="max-height:56px; max-width:76px; vertical-align:bottom; {{ $signature ? 'margin-left:6px' : '' }}">@endif
                </div>
                <div>Authorised Signatory</div>
            </td>
        </tr>
    </table>
</div>
<div class="small muted" style="margin-top:6px">
    This is an estimate, not a tax invoice. The final bill is made for the work actually done and the parts actually used.
    @if ($gst){{ $taxAdded ? 'Rates are before GST; GST is added as shown.' : 'Rates include GST; the GST inside them is shown.' }}@endif
</div>
</body>
</html>
