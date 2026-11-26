<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? __('Queue display') }}</title>
    @vite(['resources/css/app.css', 'resources/js/display.js'])
</head>
<body class="min-h-screen bg-zinc-950 text-white antialiased">
    {{ $slot }}
</body>
</html>
