@php
    $m = fn (int $c) => \App\Support\Money::format($c);
    $pct = fn (?float $p) => $p === null ? '—' : number_format($p, 1).'%';
    $s = $summary;
    $c = $comparison['summary'] ?? null;
    $groups = [
        'goods_revenue' => ['Revenue: goods', 'Goods revenue', $s['goods']['revenue'], $c['goods']['revenue'] ?? null],
        'rental_revenue' => ['Revenue: rentals', 'Rental revenue', $s['rentals']['revenue'], $c['rentals']['revenue'] ?? null],
        'costs' => ['Direct costs', 'Total direct costs', $s['direct_costs'], $c['direct_costs'] ?? null],
    ];
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Profit and loss {{ $label }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1c1917; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 13px; margin: 18px 0 6px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 10px; color: #57534e; border-bottom: 1px solid #a8a29e; padding: 4px; }
        td { border-bottom: 1px solid #e7e5e4; padding: 4px; }
        .r { text-align: right; }
        .muted { color: #57534e; }
        .group td { font-weight: bold; color: #57534e; border-bottom: 0; padding-top: 10px; font-size: 10px; }
        .sub td { font-weight: bold; }
        .total td { font-weight: bold; font-size: 13px; background: #f5f5f4; }
        .indent { padding-left: 14px; }
    </style>
</head>
<body>
    <h1>{{ $tenant }}</h1>
    <div class="muted">Profit and loss · {{ $label }} · {{ $scope }}</div>
    <div class="muted">Gross profit from goods and rentals, before tax, by invoice date. Operating expenses and net profit are in QuickBooks.</div>

    <h2>Statement</h2>
    <table>
        <tr>
            <th>Line</th>
            <th class="r">{{ $label }}</th>
            <th class="r">% of revenue</th>
            @if ($comparison)<th class="r">{{ $comparison['label'] }}</th>@endif
        </tr>
        @foreach ($groups as $group => [$heading, $subtotal, $amount, $compared])
            @if ($group === 'costs')
                <tr class="total">
                    <td>Net revenue</td>
                    <td class="r">{{ $m($s['net_revenue']) }}</td>
                    <td class="r">100.0%</td>
                    @if ($comparison)<td class="r">{{ $m($c['net_revenue']) }}</td>@endif
                </tr>
            @endif
            <tr class="group"><td colspan="{{ $comparison ? 4 : 3 }}">{{ $heading }}</td></tr>
            @foreach ($lines as $key => [$name, $lineGroup])
                @continue($lineGroup !== $group)
                <tr>
                    <td class="indent">{{ $name }}</td>
                    <td class="r">{{ $m($s['lines'][$key]) }}</td>
                    <td class="r muted">{{ $pct(\App\Reports\ProfitReport::margin($s['lines'][$key], $s['net_revenue'])) }}</td>
                    @if ($comparison)<td class="r">{{ $m($c['lines'][$key]) }}</td>@endif
                </tr>
            @endforeach
            <tr class="sub">
                <td>{{ $subtotal }}</td>
                <td class="r">{{ $m($amount) }}</td>
                <td class="r muted">{{ $pct(\App\Reports\ProfitReport::margin($amount, $s['net_revenue'])) }}</td>
                @if ($comparison)<td class="r">{{ $m((int) $compared) }}</td>@endif
            </tr>
        @endforeach
        <tr class="total">
            <td>Gross profit</td>
            <td class="r">{{ $m($s['gross_profit']) }}</td>
            <td class="r">{{ $pct($s['margin']) }}</td>
            @if ($comparison)<td class="r">{{ $m($c['gross_profit']) }}</td>@endif
        </tr>
    </table>

    <h2>Memo</h2>
    <table>
        <tr><td>Discounts given (price lists, contracts and categories)</td><td class="r">{{ $m($memo['rule_discounts']) }}</td></tr>
        <tr><td>Discounts given by hand{{ $memo['exempt_discounts'] > 0 ? ' ('.$m($memo['exempt_discounts']).' on exempt products)' : '' }}</td><td class="r">{{ $m($memo['manual_discounts']) }}</td></tr>
        <tr><td>Pallet deposits held at the end of the period (a liability, not revenue)</td><td class="r">{{ $m($memo['deposits_held']) }}</td></tr>
        @if ($memo['missing_lines'] > 0)
            <tr><td>Goods lines with no cost ({{ $memo['missing_lines'] }}), counted at no cost</td><td class="r">{{ $m($memo['missing_revenue']) }}</td></tr>
        @endif
    </table>

    @if (count($categories) > 0)
        <h2>Goods sales by category, before returns</h2>
        <table>
            <tr><th>Category</th><th class="r">Sales</th><th class="r">Cost</th><th class="r">Gross profit</th><th class="r">Margin</th></tr>
            @foreach ($categories as $row)
                <tr>
                    <td>{{ $row['category'] }}</td>
                    <td class="r">{{ $m($row['sales']) }}</td>
                    <td class="r">{{ $m($row['cost']) }}</td>
                    <td class="r">{{ $m($row['profit']) }}</td>
                    <td class="r">{{ $pct($row['margin']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
