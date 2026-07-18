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
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky stashable collapsible class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <flux:sidebar.header>
                <flux:sidebar.brand
                    href="{{ route('dashboard') }}"
                    :name="config('app.name')"
                    class="pointer-coarse:in-data-flux-sidebar-collapsed-desktop:absolute! pointer-coarse:in-data-flux-sidebar-collapsed-desktop:opacity-0!"
                >
                    <x-slot name="logo">
                        <img src="{{ asset('images/tdr-logo.svg') }}" alt="">
                    </x-slot>
                </flux:sidebar.brand>

                <flux:sidebar.collapse class="hidden lg:flex pointer-coarse:opacity-100!" />
            </flux:sidebar.header>

            <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')">
                Dashboard
            </flux:sidebar.item>

            @php
                $fastPassRecent = \App\Support\FastPass::recentFor(auth()->user());
                $fastPassTop = \App\Support\FastPass::topFor(auth()->user());
            @endphp
            <div class="px-2">
                <flux:dropdown position="bottom" align="start">
                    <flux:button variant="ghost" icon="bolt" class="w-full justify-start in-data-flux-sidebar-collapsed-desktop:justify-center">
                        <span class="in-data-flux-sidebar-collapsed-desktop:hidden">Fast Pass</span>
                    </flux:button>

                    <flux:menu>
                        <flux:menu.group heading="Recently Visited">
                            @forelse ($fastPassRecent as $visit)
                                <flux:menu.item :href="$visit->url" wire:navigate>{{ $visit->label }}</flux:menu.item>
                            @empty
                                <flux:menu.item disabled>No visits yet</flux:menu.item>
                            @endforelse
                        </flux:menu.group>

                        <flux:menu.group heading="Most Visited">
                            @forelse ($fastPassTop as $visit)
                                <flux:menu.item :href="$visit->url" wire:navigate>{{ $visit->label }}</flux:menu.item>
                            @empty
                                <flux:menu.item disabled>No visits yet</flux:menu.item>
                            @endforelse
                        </flux:menu.group>
                    </flux:menu>
                </flux:dropdown>
            </div>

            <flux:separator />

            @php
                $teacher = auth()->user()->teacher;
                $hasRegistrationAccess = $teacher?->hasActiveSchool()
                    && app(\App\Services\VersionInvitationEligibilityService::class)->hasAnyRegistrationAccess($teacher);
                $canAccessEvents = app(\App\Services\VersionRoleAssignmentService::class)->canAccessEventsSection(auth()->user());
            @endphp
            @if ($teacher?->onboarding_completed_at !== null)
                <flux:sidebar.item icon="building-library" :href="route('schools.index')" :current="request()->routeIs('schools.*')">
                    Schools
                </flux:sidebar.item>

                @if ($teacher->hasActiveSchool())
                    <flux:sidebar.item icon="users" :href="route('students.index')" :current="request()->routeIs('students.*')">
                        Students
                    </flux:sidebar.item>
                @endif

                <flux:separator />

                <flux:sidebar.item icon="building-office-2" :href="route('organizations.index')" :current="request()->routeIs('organizations.*')">
                    Organizations
                </flux:sidebar.item>

                @if ($teacher->hasActiveSchool())

                    @if ($hasRegistrationAccess)
                        <flux:sidebar.item
                            icon="clipboard-document-list"
                            :href="route('registrations.index')"
                            :current="request()->routeIs('registrations.index', 'registrations.version', 'registrations.request-invitation', 'registrations.obligations', 'registrations.candidate', 'registrations.candidate.application-pdf')"
                        >
                            Registrations
                        </flux:sidebar.item>

                        <flux:sidebar.item
                            icon="chart-bar"
                            :href="route('registrations.results-index')"
                            :current="request()->routeIs('registrations.results-index', 'registrations.results')"
                        >
                            Results
                        </flux:sidebar.item>
                    @endif

                    @if ($canAccessEvents)
                        <flux:sidebar.item icon="calendar" :href="route('events.index')" :current="request()->routeIs('events.*')">
                            Events
                        </flux:sidebar.item>
                    @endif
                @endif
            @endif

            @if (auth()->user()->isFounder())
                <flux:separator />

                <flux:sidebar.group heading="Founder" expandable>
                    <flux:sidebar.item icon="user-circle" :href="route('founder.impersonate')" :current="request()->routeIs('founder.impersonate')">
                        Impersonate User
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="arrow-path" :href="route('founder.merge-students')" :current="request()->routeIs('founder.merge-students')">
                        Merge Students
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="shield-check" :href="route('founder.teacher-verification')" :current="request()->routeIs('founder.teacher-verification')">
                        Teacher Verification
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="bolt" :href="route('founder.trackable-pages')" :current="request()->routeIs('founder.trackable-pages')">
                        Trackable Pages
                    </flux:sidebar.item>
                </flux:sidebar.group>
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
        </flux:sidebar>

        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
            <flux:spacer />
        </flux:header>

        <flux:main>
            @if (session()->has('impersonator_id'))
                <flux:callout variant="warning" icon="exclamation-triangle" class="mb-4">
                    <flux:callout.text>
                        You're impersonating {{ auth()->user()->first_name }} {{ auth()->user()->last_name }}.
                    </flux:callout.text>
                    <x-slot name="actions">
                        <form method="POST" action="{{ route('founder.stop-impersonating') }}">
                            @csrf
                            <flux:button type="submit" size="sm">Return to Founder account</flux:button>
                        </form>
                    </x-slot>
                </flux:callout>
            @endif

            {{ $slot }}
        </flux:main>

        <flux:toast />

        @fluxScripts
    </body>
</html>
