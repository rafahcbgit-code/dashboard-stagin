<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @if(app()->environment('production'))
            <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
        @endif
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @php
            $config = collect($page['props']['config']);
            $google_analytics = $config->firstWhere('key', 'google_analytics_tracking_id')['value'] ?? null;
            $favicon = $config->firstWhere('key', 'favicon')['value'] ?? null;
            $favicon = $favicon ? '/media/' . $favicon : '/images/favicon.png';
        @endphp
        <!-- Dynamic Favicon -->
        @if($favicon)
        <link rel="icon" href="{{ url($favicon) }}">
        @endif
        <link href="https://fonts.bunny.net/css2?family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
        @vite(['resources/js/app.js', 'resources/css/app.css'])
        @inertiaHead
        @if (!empty($google_analytics))
        <!-- Google tag (gtag.js) -->
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $google_analytics }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', '{{ $google_analytics }}');
        </script>
        @endif
    </head>
    <body>
        @inertia
    </body>
</html>