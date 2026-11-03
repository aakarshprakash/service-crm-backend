<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }} {{ $invoice->invoice_number }}</title>
<style>
    @page { margin: 34px 38px 40px; }
    * { font-family: Helvetica, Arial, sans-serif; }
    body { font-size: 10px; color: #111; line-height: 1.32; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }
    .frame { border: 1px solid #333; }
    .right { text-align: right; }
    .center { text-align: center; }
    .bt { border-top: 1px solid #333; }
    .bl { border-left: 1px solid #333; }
    .pad { padding: 5px 11px; }
    .company { font-size: 16px; font-weight: bold; }
    .title { text-align: center; font-size: 15px; font-weight: bold; letter-spacing: .08em; padding: 3px 0; border-top: 1px solid #333; }
    .head { font-weight: bold; font-size: 10.5px; padding: 5px 11px; }
    .party-name { font-weight: bold; font-size: 11px; }
    .kv td { padding: 1px 0; }
    .kv td.k { width: 78px; }
    .kv td.c { width: 12px; }
    .items th { border-left: 1px solid #333; padding: 6px 4px; font-size: 10px; text-align: center; vertical-align: middle; }
    .items th:first-child, .items td:first-child { border-left: 0; }
    .items thead tr { border-bottom: 1px solid #333; }
    .items td { border-left: 1px solid #333; padding: 5px 6px; font-size: 10px; }
    .totals td { padding: 2px 11px; }
    .net td { font-size: 11px; font-weight: bold; padding-top: 4px; }
    .small { font-size: 8.5px; }
    .bank td { padding: 1px 0; font-size: 9.5px; }
    .bank td.k { width: 118px; }
    .bank td.c { width: 12px; }
</style>
</head>
<body>
@php
    $product = $invoice->job?->customerProduct;
    $customer = $invoice->customer;
    $partyAddress = array_filter([$customer?->address, trim(($customer?->city ?? '').($customer?->pincode ? '-'.$customer->pincode : ''), '-')]);
    $lineCount = count($lines);
    // The item columns run down the page, as on a printed bill; long bills simply flow on.
    $fillHeight = max(14, 280 - ($lineCount * 25));
@endphp
<div class="frame">
    {{-- Company --}}
    <table>
        <tr>
            <td class="center" style="width:50%; vertical-align:middle; padding:6px 11px">
                @if ($logo)<img src="{{ $logo }}" style="max-height:82px; max-width:250px">@endif
            </td>
            <td class="bl" style="padding:6px 11px">
                <div class="company">{{ strtoupper($tenant->name) }}</div>
                @if ($tenant->address)<div>{!! nl2br(e($tenant->address)) !!}</div>@endif
                @if ($tenant->phone)<div>Phone No: {{ $tenant->phone }}</div>@endif
                @if ($tenant->email)<div>Email: {{ $tenant->email }}</div>@endif
                @if ($companyStateName)<div>State: {{ strtoupper($companyStateName) }}@if ($companyStateCode), State Code: {{ $companyStateCode }}@endif</div>@endif
            </td>
        </tr>
    </table>

    <div class="title">{{ $title }}</div>

    {{-- Registration and bill numbers --}}
    <table class="bt">
        <tr>
            <td class="pad" style="width:50%">
                <table class="kv">
                    @if ($invoice->is_gst && $tenant->gstin)<tr><td class="k">GSTIN</td><td class="c">:</td><td>{{ $tenant->gstin }}</td></tr>@endif
                    @if ($tenant->pan)<tr><td class="k">PAN</td><td class="c">:</td><td>{{ $tenant->pan }}</td></tr>@endif
                    @if ($invoice->is_gst && $invoice->place_of_supply)<tr><td class="k">Place of Supply</td><td class="c">:</td><td>{{ $invoice->place_of_supply }}</td></tr>@endif
                </table>
            </td>
            <td class="pad bl">
                <table class="kv">
                    <tr><td class="k" style="width:95px">Invoice No</td><td class="c">:</td><td><strong>{{ $invoice->invoice_number }}</strong></td></tr>
                    <tr><td class="k" style="width:95px">Invoice Date</td><td class="c">:</td><td>{{ $invoice->generated_at->timezone($tz)->format('d-m-Y') }}</td></tr>
                    @if ($invoice->job)
                        <tr><td class="k" style="width:95px">Job No &amp; Date</td><td class="c">:</td><td>{{ $invoice->job->crm_call_id }}, {{ $invoice->job->created_at?->timezone($tz)->format('d-m-Y') }}</td></tr>
                    @else
                        <tr><td class="k" style="width:95px">Order No &amp; Date</td><td class="c">:</td><td></td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    {{-- Parties --}}
    <table class="bt">
        <tr>
            <td class="head" style="width:50%">Bill To</td>
            <td class="head bl">Ship To</td>
        </tr>
    </table>
    <table class="bt">
        <tr>
            @foreach ([true, false] as $billTo)
                <td class="{{ $billTo ? '' : 'bl' }}" style="width:50%; padding:6px 11px 6px 28px">
                    <div class="party-name">{{ strtoupper($customer?->name ?? '') }}</div>
                    @foreach ($partyAddress as $line)<div>{{ $line }}</div>@endforeach
                    @if ($customer?->phone)<div>Phone: {{ $customer->phone }}</div>@endif
                    @if ($customerStateName)<div>State: {{ $customerStateName }}@if ($customerStateCode), State Code: {{ $customerStateCode }}@endif</div>@endif
                    @if ($invoice->is_gst)<div>GSTIN: {{ $invoice->customer_gstin ?: 'Unregistered' }}</div>@endif
                    @if ($billTo && $customer?->name)<div>Contact Person: {{ $customer->name }}</div>@endif
                </td>
            @endforeach
        </tr>
    </table>

    {{-- Items --}}
    <table class="items bt">
        <thead>
            <tr>
                <th style="width:6%">S.No</th>
                <th>Description of Goods / Services</th>
                <th style="width:10%">HSN / SAC</th>
                <th style="width:7%">Qty</th>
                <th style="width:7%">UOM</th>
                <th style="width:11%">Rate<br>(Rs.)</th>
                <th style="width:11%">Amount<br>(Rs.)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $i => $l)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td>{{ $l['description'] }}</td>
                    <td class="center">{{ $l['hsn'] ?? '' }}</td>
                    <td class="center">{{ $qty($l['qty']) }}</td>
                    <td class="center">{{ strtoupper($l['uom'] ?? 'nos') }}</td>
                    <td class="right">{{ $amount($invoice->is_gst ? $l['unit_taxable'] : ((float) $l['qty'] > 0 ? (int) round($l['gross'] / (float) $l['qty']) : $l['gross'])) }}</td>
                    <td class="right">{{ $amount($invoice->is_gst ? $l['taxable'] : $l['gross']) }}</td>
                </tr>
            @endforeach
            <tr><td style="height:{{ $fillHeight }}px"></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
        </tbody>
    </table>

    {{-- Bank details and tax --}}
    <table class="bt">
        <tr>
            <td class="pad" style="width:58%">
                <div style="font-weight:bold; font-size:10.5px; margin-bottom:2px">Additional Information :</div>
                <table>
                    <tr>
                        <td style="padding:0">
                            @if (array_filter($bank))
                                <table class="bank">
                                    @if ($bank['account_name'])<tr><td class="k">Account Holders Name</td><td class="c">:</td><td><strong>{{ $bank['account_name'] }}</strong></td></tr>@endif
                                    @if ($bank['bank_name'])<tr><td class="k">BANK</td><td class="c">:</td><td><strong>{{ $bank['bank_name'] }}</strong></td></tr>@endif
                                    @if ($bank['account_no'])<tr><td class="k">Account No</td><td class="c">:</td><td><strong>{{ $bank['account_no'] }}</strong></td></tr>@endif
                                    @if ($bank['ifsc'])<tr><td class="k">IFSC</td><td class="c">:</td><td><strong>{{ $bank['ifsc'] }}{{ $bank['branch'] ? ', BRANCH: '.$bank['branch'] : '' }}</strong></td></tr>@endif
                                </table>
                            @endif
                            @if ($invoice->notes)<div style="margin-top:3px">{{ $invoice->notes }}</div>@endif
                            @if ($invoice->is_gst && $discount > 0)<div class="small" style="margin-top:3px">Rates are after a discount of Rs. {{ $amount($discount) }}.</div>@endif
                        </td>
                        @if ($upiQr)
                            <td class="center" style="width:82px; padding:0">
                                <img src="{{ $upiQr }}" alt="UPI QR" style="width:68px; height:68px">
                                <div class="small">Scan to pay Rs. {{ $amount($upi['amount']) }}</div>
                            </td>
                        @endif
                    </tr>
                </table>
            </td>
            <td class="bl" style="padding:3px 0">
                <table class="totals">
                    <tr><td><strong>Amount (Rs.)</strong></td><td class="right"><strong>{{ $amount($invoice->is_gst ? $invoice->taxable_amount : $gross) }}</strong></td></tr>
                    @if (! $invoice->is_gst && $discount > 0)
                        <tr><td>Less: Discount</td><td class="right">- {{ $amount($discount) }}</td></tr>
                    @endif
                    @foreach ($taxRates as $tax)
                        @if ($interState)
                            <tr><td>IGST @ {{ $tax['rate'] }}%</td><td class="right">{{ $amount($tax['igst']) }}</td></tr>
                        @else
                            <tr><td>CGST @ {{ $tax['half'] }}%</td><td class="right">{{ $amount($tax['cgst']) }}</td></tr>
                            <tr><td>SGST @ {{ $tax['half'] }}%</td><td class="right">{{ $amount($tax['sgst']) }}</td></tr>
                        @endif
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

    {{-- Amount in words and totals --}}
    <table class="bt">
        <tr>
            <td class="pad" style="width:58%"><strong style="font-size:10.5px">Amount in words : {{ $words }}</strong></td>
            <td class="bl" style="padding:3px 0">
                <table class="totals">
                    <tr class="net"><td>Net Total (Rs.)</td><td class="right">{{ $amount($invoice->total_amount) }}</td></tr>
                    <tr><td>Amount Paid</td><td class="right">{{ $amount($invoice->paid_amount) }}</td></tr>
                    <tr class="net"><td>Balance Due</td><td class="right">{{ $amount($invoice->balance_amount) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Service details (a counter sale has the delivery references instead), and the certificate --}}
    <table class="bt">
        <tr>
            <td class="pad" style="width:58%">
                <table class="kv">
                    @if ($invoice->job)
                        @if ($product?->model_no || $product?->product?->brand || $product?->brand)<tr><td class="k">Product</td><td class="c">:</td><td>{{ $product->product?->brand?->name ?? $product->brand?->name }} {{ $product->model_no }}</td></tr>@endif
                        @if ($product?->serial_no)<tr><td class="k">Serial No</td><td class="c">:</td><td>{{ $product->serial_no }}</td></tr>@endif
                        @if ($invoice->job->complaintType)<tr><td class="k">Service Type</td><td class="c">:</td><td>{{ $invoice->job->complaintType->name }}</td></tr>@endif
                    @else
                        <tr><td class="k">Lot Code</td><td class="c">:</td><td></td></tr>
                        <tr><td class="k">LR No</td><td class="c">:</td><td></td></tr>
                    @endif
                </table>
            </td>
            <td class="bl small" style="padding:5px 11px">
                Certified that the particulars given above are true and correct and the amount indicated represents the price actually charged and that there is no additional consideration directly or indirectly from the buyer
            </td>
        </tr>
    </table>

    {{-- Signatures --}}
    <table class="bt">
        <tr>
            <td style="width:58%; padding:6px 11px; height:92px; position:relative">
                <div>Received the above {{ $invoice->job ? 'goods / services' : 'goods' }} in good condition</div>
                @if ($signature)
                    <div style="margin-top:4px">
                        <img src="{{ $signature['src'] }}" alt="Customer signature" style="height:40px; max-width:170px">
                        <div class="small">{{ $signature['name'] }}{{ $signature['name'] ? ', ' : '' }}{{ $signature['at']->timezone($tz)->format('d-m-Y h:i A') }}</div>
                    </div>
                @else
                    <div style="height:50px"></div>
                @endif
                <div style="margin-top:4px">Please return one copy of challan with seal &amp; signature</div>
            </td>
            <td class="bl right" style="padding:6px 11px">
                <div>For {{ strtoupper($tenant->name) }}</div>
                <div style="height:64px"></div>
                <div>Authorised Signatory</div>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
