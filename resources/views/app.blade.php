<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: #f5f3ef;
            }

            html.dark {
                background-color: #141210;
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- The installable staff app. Not on the contractor portal or customers' public pages. --}}
        @unless (str_starts_with($page['component'], 'portal/') || str_starts_with($page['component'], 'public/'))
            <link rel="manifest" href="/manifest.webmanifest">
            <meta name="theme-color" content="#f5f3ef" media="(prefers-color-scheme: light)">
            <meta name="theme-color" content="#141210" media="(prefers-color-scheme: dark)">
            <meta name="mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-title" content="YardRunner">
            <meta name="apple-mobile-web-app-status-bar-style" content="default">
        @endunless

        {{-- Link previews for public pages only (see App\Support\ShareMeta) --}}
        @if ($share = \App\Support\ShareMeta::for(request(), $page))
            <meta name="description" content="{{ $share['description'] }}">
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="YardRunner">
            <meta property="og:title" content="{{ $share['title'] }}">
            <meta property="og:description" content="{{ $share['description'] }}">
            <meta property="og:url" content="{{ $share['url'] }}">
            <meta property="og:image" content="{{ $share['image'] }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            <meta property="og:image:alt" content="YardRunner: inventory, orders and rentals for masonry and landscape supply yards">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="{{ $share['title'] }}">
            <meta name="twitter:description" content="{{ $share['description'] }}">
            <meta name="twitter:image" content="{{ $share['image'] }}">
        @endif

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'YardRunner') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
