@php
    /** @var \App\Email\EmailTheme $theme */
    $plain = fn ($value) => html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
@endphp
{!! $plain($theme->name) !!}

@if ($notice){!! $plain($notice['text']) !!}

@endif
{!! $plain($title ?? $subject ?? $theme->name) !!}

@if ($greeting){!! $plain($greeting) !!}

@endif
@foreach ($introLines as $line){!! $plain($line) !!}

@endforeach
@foreach ($facts as $label => $value){!! $plain($label) !!}: {!! $plain($value) !!}
@endforeach
@if ($facts)

@endif
@if ($actionText && $actionUrl){!! $plain($actionText) !!}: {!! $actionUrl !!}

@endif
@foreach ($outroLines as $line){!! $plain($line) !!}

@endforeach
@if ($salutation){!! $plain($salutation) !!}

@endif
--
@if ($theme->isYardRunner)
@foreach ($theme->footerLines as $line){!! $plain($line) !!}
@endforeach
@else
@if ($theme->contactName){!! $plain($theme->contactName) !!}
@endif
@foreach ($theme->contactLines as $line){!! $plain($line) !!}
@endforeach
@if ($theme->replyLine){!! $plain($theme->replyLine) !!}
@endif
@foreach ($theme->footerLines as $line){!! $plain($line) !!}
@endforeach
@if ($theme->sentWithYardRunner)Sent with YardRunner
@endif
@endif
