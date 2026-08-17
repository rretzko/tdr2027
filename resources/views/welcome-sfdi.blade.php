<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ \App\Support\PortalBranding::appName(sfdi: true) }}</title>

        @include('partials.favicon', ['logo' => 'sfdi-logo.svg'])

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link rel="stylesheet" href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600">

        @fluxAppearance

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex min-h-screen flex-col bg-white antialiased dark:bg-zinc-900">
        <header class="p-6">
            <nav class="mx-auto flex w-full max-w-[1750px] flex-wrap items-center justify-end gap-2 text-sm">
                @auth
                    <flux:button :href="route('dashboard')" variant="primary">Dashboard</flux:button>
                @else
                    <flux:button :href="route('sfdi.login')" variant="ghost">Log In</flux:button>
                    <flux:button :href="route('sfdi.register')" variant="primary">Register</flux:button>
                @endauth
            </nav>
        </header>

        <main class="mx-auto flex w-full max-w-[1750px] flex-1 flex-col items-center justify-center gap-6 p-6 text-center">
            <div class="w-full max-w-2xl">
                <img src="{{ asset('images/sfdi-logo.svg') }}" alt="" class="mx-auto h-16 w-16">
                <flux:heading size="xl">StudentFolder.info</flux:heading>
                <flux:subheading>Registration and audition materials for students</flux:subheading>
            </div>

            <img
                src="https://auditionsuite-production.s3.amazonaws.com/backgrounds/musicStand.png"
                alt=""
                class="w-full max-w-3xl"
            >

            <flux:button :href="route('sfdi.register')" variant="primary">Register as a Student</flux:button>
        </main>

        <footer class="flex flex-col items-center gap-1 p-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
            <p>&copy; {{ date('Y') }} StudentFolder.info &middot; v{{ config('app.version') }}</p>
            <a href="mailto:support@studentfolder.info" class="underline">Contact Us</a>
        </footer>

        @fluxScripts
    </body>
</html>
