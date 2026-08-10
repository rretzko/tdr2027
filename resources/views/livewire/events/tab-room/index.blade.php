<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Tab Room</span>
    </div>

    <div class="mb-6">
        <flux:heading size="xl">Tab Room</flux:heading>
        <flux:text size="sm" class="text-zinc-500">{{ $version->name }}</flux:text>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @php
            $modules = [
                ['route' => 'events.versions.tab-room.scores', 'name' => 'Add/Edit Scores', 'description' => 'Review and create/update judge scores for a candidate.', 'available' => true],
                ['route' => 'events.versions.tab-room.tracking', 'name' => 'Adjudication Tracking', 'description' => "Track each Room's judging progress.", 'available' => true],
                ['route' => null, 'name' => 'Ensemble Cut-offs', 'description' => 'Set cut-off scores and assign candidates to Ensembles.', 'available' => false],
                ['route' => null, 'name' => 'Reports', 'description' => 'Audition scores, participation, and seniority reports.', 'available' => false],
                ['route' => null, 'name' => 'Close Audition', 'description' => 'Close the audition and release results to teachers.', 'available' => false],
            ];
        @endphp

        @foreach ($modules as $module)
            @if ($module['available'])
                <flux:card class="hover:border-zinc-300 dark:hover:border-zinc-600">
                    <a href="{{ route($module['route'], $version) }}" wire:navigate class="block">
                        <flux:heading size="base">{{ $module['name'] }}</flux:heading>
                        <flux:text size="sm" class="text-zinc-500 mt-1">{{ $module['description'] }}</flux:text>
                    </a>
                </flux:card>
            @else
                <flux:card class="opacity-50">
                    <flux:heading size="base">{{ $module['name'] }}</flux:heading>
                    <flux:text size="sm" class="text-zinc-500 mt-1">{{ $module['description'] }}</flux:text>
                    <flux:badge size="sm" color="zinc" class="mt-2">Coming soon</flux:badge>
                </flux:card>
            @endif
        @endforeach
    </div>
</div>
