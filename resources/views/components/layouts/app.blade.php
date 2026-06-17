<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ?? config('app.name') }}</title>

        @fonts

        @fluxAppearance

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky stashable class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('dashboard') }}" class="flex items-center gap-2 px-2 py-2 font-semibold text-zinc-900 dark:text-white">
                {{ config('app.name') }}
            </a>

            <flux:navlist variant="outline">
                <flux:navlist.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')">
                    Dashboard
                </flux:navlist.item>

                @if (auth()->user()->teacher?->onboarding_completed_at !== null)
                    <flux:navlist.item icon="building-library" :href="route('schools.index')" :current="request()->routeIs('schools.*')">
                        Schools
                    </flux:navlist.item>
                    <flux:navlist.item icon="users" :href="route('students.index')" :current="request()->routeIs('students.*')">
                        Students
                    </flux:navlist.item>

                    <flux:separator />

                    <flux:navlist.item icon="building-office-2" :href="route('organizations.index')" :current="request()->routeIs('organizations.*')">
                        Organizations
                    </flux:navlist.item>
                    <flux:navlist.item icon="calendar" :href="route('events.index')" :current="request()->routeIs('events.*')">
                        Events
                    </flux:navlist.item>
                @endif
            </flux:navlist>

            <flux:spacer />

            <flux:navlist variant="outline">
                <flux:navlist.item icon="user" :href="route('settings.profile')" :current="request()->routeIs('settings.profile')">
                    Profile
                </flux:navlist.item>
                <flux:navlist.item icon="key" :href="route('settings.password')" :current="request()->routeIs('settings.password')">
                    Password
                </flux:navlist.item>
            </flux:navlist>

            <div class="flex items-center gap-2 px-2 py-2" x-data="{ dark: document.documentElement.classList.contains('dark') }">
                <flux:icon.sun variant="micro" class="text-zinc-400" />
                <flux:switch x-model="dark" x-on:change="$flux.appearance = dark ? 'dark' : 'light'" />
                <flux:icon.moon variant="micro" class="text-zinc-400" />
            </div>

            <div class="flex items-center gap-2 px-2 py-2">
                @php $avatar = auth()->user()->avatarUrl(); @endphp
                @if($avatar)
                    <img src="{{ $avatar }}" alt="" class="h-7 w-7 rounded-full object-cover">
                @else
                    <flux:icon.user-circle class="h-7 w-7 text-zinc-400" />
                @endif
                <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ auth()->user()->sort_name }}</span>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button type="submit" variant="ghost" icon="arrow-right-start-on-rectangle" class="w-full justify-start">
                    Log out
                </flux:button>
            </form>
        </flux:sidebar>

        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
            <flux:spacer />
        </flux:header>

        <flux:main>
            {{ $slot }}
        </flux:main>

        @fluxScripts
    </body>
</html>
