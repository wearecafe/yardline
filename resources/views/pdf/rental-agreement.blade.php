<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Rental agreement {{ $rental->number }}</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5pt; color: #1c1917; line-height: 1.45; }
    h1 { font-size: 16pt; margin: 0 0 2pt; }
    .muted { color: #57534e; }
    table.summary { width: 100%; border-collapse: collapse; margin: 14pt 0; }
    table.summary td { padding: 5pt 0; border-bottom: 0.5pt solid #e7e5e4; vertical-align: top; }
    table.summary td.label { width: 32%; color: #57534e; }
    .terms { white-space: pre-line; border: 0.5pt solid #d6d3d1; padding: 10pt; margin: 10pt 0 16pt; }
    .signature { height: 70pt; margin: 6pt 0; }
    .record { font-size: 8.5pt; color: #57534e; margin-top: 18pt; }
</style>
</head>
<body>
    <h1>Rental agreement · {{ $rental->number }}</h1>
    <div class="muted">{{ $tenant->name }}</div>

    <table class="summary">
        <tr><td class="label">Customer</td><td>{{ $agreement->summary['customer'] ?? '' }}</td></tr>
        <tr><td class="label">Equipment</td><td>{{ implode(', ', $agreement->summary['equipment'] ?? []) }}</td></tr>
        <tr><td class="label">Out</td><td>{{ $agreement->summary['out'] ?? '' }}</td></tr>
        <tr><td class="label">Due back</td><td>{{ $agreement->summary['due_back'] ?? '' }}</td></tr>
        <tr><td class="label">Rental total, incl. tax</td><td>{{ $agreement->summary['total'] ?? '' }}</td></tr>
        <tr><td class="label">Deposit hold</td><td>{{ $agreement->summary['deposit'] ?? '' }}</td></tr>
        <tr><td class="label">Late return</td><td>{{ $agreement->summary['late_return'] ?? '' }}</td></tr>
        <tr><td class="label">Damage waiver</td><td>{{ $agreement->summary['damage_waiver'] ?? '' }}</td></tr>
    </table>

    <strong>Terms</strong>
    <div class="terms">{{ $agreement->terms }}</div>

    <div>The renter has read these terms and is trained to operate this equipment safely.</div>

    @if ($signature)
        <img class="signature" src="{{ $signature }}" alt="Signature">
    @endif
    <div>{{ $agreement->signer_name }}</div>

    <div class="record">
        Signed {{ $agreement->signed_at?->setTimezone($tenant->timezone)->format('M j, Y g:i:s a T') }}
        {{ $agreement->signed_via === 'counter' ? 'at the counter' : 'online' }}
        @if ($agreement->idDocument) · Driver’s licence verified @endif
        · IP {{ $agreement->signed_ip }} · {{ $agreement->signed_user_agent }}
    </div>
</body>
</html>
