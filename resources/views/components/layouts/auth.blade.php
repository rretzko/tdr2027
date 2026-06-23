<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ?? config('app.name') }}</title>

        @include('partials.favicon')

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link rel="stylesheet" href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600">

        @fluxAppearance

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-zinc-50 antialiased dark:bg-zinc-900">
        <div class="flex min-h-screen flex-col items-center justify-center gap-6 p-6">
            <a href="{{ url('/') }}" class="flex items-center gap-2 text-lg font-semibold text-zinc-900 dark:text-white">
                <img src="{{ asset('images/tdr-logo.svg') }}" alt="" class="h-8 w-8">
                {{ config('app.name') }}
            </a>

            <flux:card class="w-full max-w-md">
                {{ $slot }}
            </flux:card>
        </div>

        @fluxScripts
    </body>
</html>
