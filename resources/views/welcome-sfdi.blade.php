<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Laravel') }}</title>

        @include('partials.favicon')

        @fonts

        @fluxAppearance

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex min-h-screen flex-col bg-zinc-50 antialiased dark:bg-zinc-900">
        <header class="p-6">
            <nav class="flex items-center justify-end gap-4 text-sm">
                @auth
                    <flux:button :href="route('dashboard')" variant="primary">Dashboard</flux:button>
                @else
                    <flux:button :href="route('login')" variant="ghost">Log in</flux:button>
                @endauth
            </nav>
        </header>

        <main class="flex flex-1 flex-col p-6">
            <div
                class="relative flex flex-1 items-center justify-center rounded-lg bg-contain bg-center bg-no-repeat"
                style="background-image: url('https://auditionsuite-production.s3.amazonaws.com/backgrounds/musicStand.png')"
            >
                <div class="flex flex-col items-center gap-4 rounded-lg bg-white/80 p-8 text-center backdrop-blur-sm dark:bg-zinc-900/80">
                    <flux:heading size="xl">StudentFolder.info</flux:heading>
                    <flux:subheading>Registration and audition materials for students</flux:subheading>
                    <flux:button :href="route('sfdi.register')" variant="primary">Register as a Student</flux:button>
                </div>
            </div>
        </main>

        <footer class="p-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
            &copy; {{ date('Y') }} StudentFolder.info
        </footer>

        @fluxScripts
    </body>
</html>
