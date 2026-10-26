@php($m = fn (int $c) => \App\Support\Money::format($c))
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Statement {{ $s['organization']['name'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1c1917; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 13px; margin: 18px 0 6px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 10px; color: #57534e; border-bottom: 1px solid #a8a29e; padding: 4px; }
        td { border-bottom: 1px solid #e7e5e4; padding: 4px; }
        .r { text-align: right; }
        .muted { color: #57534e; }
        .total { font-size: 14px; font-weight: bold; }
        .aging td { border: 1px solid #e7e5e4; text-align: center; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td style="border:0">
                <h1>{{ $tenant }}</h1>
                <div class="muted">Statement of account · {{ \Carbon\Carbon::parse($s['date'])->format('F j, Y') }}</div>
            </td>
            <td style="border:0" class="r">
                <strong>{{ $s['organization']['name'] }}</strong><br>
                Account {{ $s['organization']['account_number'] }}<br>
                @foreach ($s['organization']['address'] as $line){{ $line }}<br>@endforeach
                Terms: {{ $s['organization']['terms'] }}
            </td>
        </tr>
    </table>

    <h2>Open invoices</h2>
    <table>
        <tr><th>Invoice</th><th>Order</th><th>PO</th><th>Issued</th><th>Due</th><th class="r">Amount</th><th class="r">Paid</th><th class="r">Balance</th></tr>
        @forelse ($s['invoices'] as $i)
            <tr>
                <td>{{ $i['number'] }}</td><td>{{ $i['order_number'] }}</td><td>{{ $i['po_number'] }}</td>
                <td>{{ $i['issued_on'] }}</td><td>{{ $i['due_on'] }}@if($i['days_overdue'] > 0) <span class="muted">({{ $i['days_overdue'] }} days late)</span>@endif</td>
                <td class="r">{{ $m($i['total_cents']) }}</td><td class="r">{{ $m($i['paid_cents']) }}</td><td class="r">{{ $m($i['balance_cents']) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">Nothing open.</td></tr>
        @endforelse
    </table>

    @if (count($s['credits']))
        <h2>Credits</h2>
        <table>
            <tr><th>Credit note</th><th>Date</th><th>For</th><th class="r">Amount</th><th class="r">Unused</th></tr>
            @foreach ($s['credits'] as $c)
                <tr><td>{{ $c['number'] }}</td><td>{{ $c['issued_on'] }}</td><td>{{ $c['reason'] }}</td><td class="r">{{ $m($c['total_cents']) }}</td><td class="r">{{ $m($c['remaining_cents']) }}</td></tr>
            @endforeach
        </table>
    @endif

    @if (count($s['payments']))
        <h2>Payments, last 60 days</h2>
        <table>
            <tr><th>Date</th><th>Order</th><th>How</th><th>Reference</th><th class="r">Amount</th></tr>
            @foreach ($s['payments'] as $p)
                <tr><td>{{ $p['date'] }}</td><td>{{ $p['order_number'] }}</td><td>{{ $p['tender'] }}</td><td>{{ $p['reference'] }}</td><td class="r">{{ $m($p['amount_cents']) }}</td></tr>
            @endforeach
        </table>
    @endif

    <h2>Aging</h2>
    <table class="aging">
        <tr>@foreach ($s['bucket_labels'] as $label)<th style="text-align:center">{{ $label }}</th>@endforeach</tr>
        <tr>@foreach (array_keys($s['bucket_labels']) as $key)<td>{{ $m($s['buckets'][$key]) }}</td>@endforeach</tr>
    </table>

    <p class="r total" style="margin-top:14px">Balance due: {{ $s['balance'] }}</p>
    @if ($s['pallets_out'] > 0)
        <p class="muted">Pallets out on deposit: {{ $s['pallets_out'] }}. Bring them back for a credit.</p>
    @endif
</body>
</html>
