<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ?? config('app.name') }}</title>

        @include('partials.favicon')

        @fonts

        @fluxAppearance

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky stashable collapsible class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <flux:sidebar.brand href="{{ route('dashboard') }}" :name="config('app.name')">
                <x-slot name="logo">
                    <img src="{{ asset('images/tdr-logo.svg') }}" alt="">
                </x-slot>
            </flux:sidebar.brand>

            <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')">
                Dashboard
            </flux:sidebar.item>

            @if (auth()->user()->teacher?->onboarding_completed_at !== null)
                <flux:sidebar.item icon="building-library" :href="route('schools.index')" :current="request()->routeIs('schools.*')">
                    Schools
                </flux:sidebar.item>
                <flux:sidebar.item icon="users" :href="route('students.index')" :current="request()->routeIs('students.*')">
                    Students
                </flux:sidebar.item>

                <flux:separator />

                <flux:sidebar.item icon="building-office-2" :href="route('organizations.index')" :current="request()->routeIs('organizations.*')">
                    Organizations
                </flux:sidebar.item>
                <flux:sidebar.item icon="calendar" :href="route('events.index')" :current="request()->routeIs('events.*')">
                    Events
                </flux:sidebar.item>
            @endif

            <flux:spacer />

            <flux:sidebar.item icon="user" :href="route('settings.profile')" :current="request()->routeIs('settings.profile')">
                Profile
            </flux:sidebar.item>
            <flux:sidebar.item icon="key" :href="route('settings.password')" :current="request()->routeIs('settings.password')">
                Password
            </flux:sidebar.item>

            <div class="flex items-center gap-2 px-2 py-2 in-data-flux-sidebar-collapsed-desktop:justify-center" x-data="{ dark: document.documentElement.classList.contains('dark') }">
                <flux:icon.sun variant="micro" class="text-zinc-400" />
                <flux:switch x-model="dark" x-on:change="$flux.appearance = dark ? 'dark' : 'light'" class="in-data-flux-sidebar-collapsed-desktop:hidden" />
                <flux:icon.moon variant="micro" class="text-zinc-400 in-data-flux-sidebar-collapsed-desktop:hidden" />
            </div>

            <div class="flex items-center gap-2 px-2 py-2 in-data-flux-sidebar-collapsed-desktop:justify-center">
                @php $avatar = auth()->user()->avatarUrl(); @endphp
                @if($avatar)
                    <img src="{{ $avatar }}" alt="" class="h-7 w-7 rounded-full object-cover">
                @else
                    <flux:icon.user-circle class="h-7 w-7 text-zinc-400" />
                @endif
                <span class="text-sm text-zinc-600 dark:text-zinc-300 in-data-flux-sidebar-collapsed-desktop:hidden">{{ auth()->user()->sort_name }}</span>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button type="submit" variant="ghost" icon="arrow-right-start-on-rectangle" class="w-full justify-start in-data-flux-sidebar-collapsed-desktop:justify-center">
                    <span class="in-data-flux-sidebar-collapsed-desktop:hidden">Log out</span>
                </flux:button>
            </form>

            <flux:sidebar.collapse class="hidden lg:flex" />
        </flux:sidebar>

        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
            <flux:spacer />
        </flux:header>

        <flux:main>
            {{ $slot }}
        </flux:main>

        <flux:toast />

        @fluxScripts
    </body>
</html>
