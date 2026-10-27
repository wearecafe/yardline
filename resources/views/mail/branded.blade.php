@php
    /** @var \App\Email\EmailTheme $theme */
    $c = $theme->colours;
    $font = "-apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $heading = $title ?? $subject ?? $theme->name;
    $preheader = $introLines[0] ?? '';
    $tones = [
        'success' => ['#E8F4EC', '#1E6B3A'],
        'warning' => ['#FFF4E5', '#6B3B00'],
        'danger' => ['#FDECEA', $c['danger']],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $heading }}</title>
<style>
    @media only screen and (max-width: 620px) {
        .yr-pad { padding-left: 20px !important; padding-right: 20px !important; }
        .yr-note { display: none !important; }
    }
    a { color: {{ $c['primary_strong'] }}; }
</style>
</head>
<body style="margin: 0; padding: 0; background: {{ $c['background'] }}; -webkit-text-size-adjust: 100%;">
@if ($preheader !== '')
<div style="display: none; max-height: 0; overflow: hidden; opacity: 0; color: transparent;">{{ \Illuminate\Support\Str::limit(strip_tags((string) $preheader), 140) }}</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background: {{ $c['background'] }};">
<tr>
<td align="center" style="padding: 24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px; background: {{ $c['surface'] }}; border: 1px solid {{ $c['line'] }}; border-radius: 8px; font-family: {{ $font }}; color: {{ $c['ink'] }};">
    @if ($theme->headerStyle !== 'brand')
    <tr><td style="height: 4px; line-height: 4px; font-size: 0; background: {{ $c['primary'] }}; border-radius: 8px 8px 0 0;">&nbsp;</td></tr>
    @endif
    <tr>
        <td class="yr-pad" style="padding: 20px 32px; background: {{ $theme->headerBackground() }}; border-bottom: 1px solid {{ $c['line'] }};{{ $theme->headerStyle === 'brand' ? ' border-radius: 8px 8px 0 0;' : '' }}">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="vertical-align: middle;">
                        @if ($theme->isYardRunner)
                            <img src="{{ asset('icons/icon-192.png') }}" width="28" height="28" alt="" style="vertical-align: middle; border: 0; border-radius: 6px;">
                            <span style="vertical-align: middle; font-size: 18px; font-weight: 800; letter-spacing: -0.02em; color: {{ $theme->headerText() }}; padding-left: 6px;">YardRunner</span>
                        @elseif ($theme->logoUrl)
                            <span style="display: inline-block; background: #FFFFFF; border-radius: 6px; padding: {{ $theme->headerStyle === 'brand' ? '6px 8px' : '0' }};">
                                <img src="{{ $theme->logoUrl }}" alt="{{ $theme->name }}" height="40" style="display: block; height: 40px; width: auto; max-width: 200px; border: 0;">
                            </span>
                        @else
                            <span style="font-size: 19px; font-weight: 700; color: {{ $theme->headerText() }};">{{ $theme->name }}</span>
                        @endif
                    </td>
                    @if ($theme->headerNote)
                    <td class="yr-note" align="right" style="vertical-align: middle; font-size: 12px; color: {{ $theme->headerStyle === 'brand' ? $theme->headerText() : $c['muted'] }};">{{ $theme->headerNote }}</td>
                    @endif
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td class="yr-pad" style="padding: 28px 32px 8px;">
            @if ($notice)
                @php [$noticeBg, $noticeFg] = $tones[$notice['tone']] ?? $tones['success']; @endphp
                <div style="display: inline-block; background: {{ $noticeBg }}; color: {{ $noticeFg }}; font-size: 13px; font-weight: 600; padding: 4px 10px; border-radius: 999px; margin-bottom: 12px;">{{ $notice['text'] }}</div>
            @endif
            <h1 style="margin: 0 0 12px; font-size: 22px; line-height: 1.3; font-weight: 700; color: {{ $c['ink'] }};">{{ $heading }}</h1>
            @if ($greeting)
                <p style="margin: 0 0 12px; font-size: 15px; line-height: 1.5;">{{ $greeting }}</p>
            @endif
            @foreach ($introLines as $line)
                <p style="margin: 0 0 12px; font-size: 15px; line-height: 1.5;">{{ $line }}</p>
            @endforeach
        </td>
    </tr>
    @if ($facts)
    <tr>
        <td class="yr-pad" style="padding: 8px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background: {{ $c['primary_subtle'] }}; border: 1px solid {{ $c['primary_border'] }}; border-radius: 6px;">
                @foreach (array_chunk($facts, 2, true) as $pair)
                <tr>
                    @foreach ($pair as $label => $value)
                    <td width="50%" style="padding: 12px 16px; vertical-align: top;">
                        <div style="font-size: 12px; color: {{ $c['muted'] }};">{{ $label }}</div>
                        <div style="font-size: 15px; font-weight: 600; color: {{ $c['ink'] }};">{{ $value }}</div>
                    </td>
                    @endforeach
                    @if (count($pair) === 1)<td width="50%"></td>@endif
                </tr>
                @endforeach
            </table>
        </td>
    </tr>
    @endif
    @if ($actionText && $actionUrl)
    <tr>
        <td class="yr-pad" style="padding: 12px 32px 16px;">
            <table role="presentation" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="background: {{ $theme->buttonFill }}; border-radius: 6px;">
                        <a href="{{ $actionUrl }}" target="_blank" rel="noopener" style="display: inline-block; padding: 12px 22px; font-size: 15px; font-weight: 600; line-height: 20px; color: {{ $theme->buttonText }}; text-decoration: none; font-family: {{ $font }};">{{ $actionText }}</a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    @endif
    @if ($outroLines || $salutation)
    <tr>
        <td class="yr-pad" style="padding: 8px 32px 8px;">
            @foreach ($outroLines as $line)
                <p style="margin: 0 0 12px; font-size: 15px; line-height: 1.5;">{{ $line }}</p>
            @endforeach
            @if ($salutation)
                <p style="margin: 0 0 12px; font-size: 15px; line-height: 1.5;">{{ $salutation }}</p>
            @endif
        </td>
    </tr>
    @endif
    @if ($actionText && $actionUrl)
    <tr>
        <td class="yr-pad" style="padding: 0 32px 8px; font-size: 12px; line-height: 1.5; color: {{ $c['muted'] }};">
            If the button doesn’t work, copy this link into your browser:<br>
            <a href="{{ $actionUrl }}" style="color: {{ $c['primary_strong'] }}; word-break: break-all;">{{ $displayableActionUrl }}</a>
        </td>
    </tr>
    @endif
    <tr>
        <td class="yr-pad" style="padding: 8px 32px 24px;">
            <div style="border-top: 1px solid {{ $c['line'] }}; padding-top: 16px; font-size: 13px; line-height: 1.6; color: {{ $c['muted'] }};">
                @if ($theme->isYardRunner)
                    @foreach ($theme->footerLines as $line)
                        <div style="font-size: 12px;">{{ $line }}</div>
                    @endforeach
                @else
                    @if ($theme->contactName)
                        <div style="color: {{ $c['ink'] }}; font-weight: 600;">{{ $theme->contactName }}</div>
                    @endif
                    @foreach ($theme->contactLines as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                    @if ($theme->replyLine)
                        <div>{{ $theme->replyLine }}</div>
                    @endif
                @endif
            </div>
        </td>
    </tr>
</table>
@unless ($theme->isYardRunner)
    @if ($theme->footerLines || $theme->sentWithYardRunner)
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px;">
        <tr>
            <td align="center" style="padding: 16px 12px 0; font-family: {{ $font }}; font-size: 12px; line-height: 1.6; color: {{ $c['muted'] }};">
                @foreach ($theme->footerLines as $line)
                    <div>{{ $line }}</div>
                @endforeach
                @if ($theme->sentWithYardRunner)
                    <div>Sent with <a href="https://yardrunner.ca" style="color: {{ $c['muted'] }};">YardRunner</a></div>
                @endif
            </td>
        </tr>
    </table>
    @endif
@endunless
</td>
</tr>
</table>
</body>
</html>
