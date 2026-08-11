<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.tab-room.index', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Tab Room</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Reports</span>
    </div>

    <div class="mb-6">
        <flux:heading size="xl">Reports</flux:heading>
        <flux:text size="sm" class="text-zinc-500">{{ $version->name }}</flux:text>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @php
            $reports = [
                ['route' => 'events.versions.tab-room.reports.audition-scores', 'name' => 'Audition Scores', 'description' => 'Per-judge, per-factor scores for one Voice Part.'],
                ['route' => 'events.versions.tab-room.reports.combined-scores-confidential', 'name' => 'Combined Audition Scores (Confidential)', 'description' => 'Every Voice Part in an Ensemble, with student/school/teacher identity.'],
                ['route' => 'events.versions.tab-room.reports.combined-scores-public', 'name' => 'Combined Audition Scores (Public)', 'description' => 'Every Voice Part in an Ensemble, scores only — no identity.'],
                ['route' => 'events.versions.tab-room.reports.ensemble-participation', 'name' => 'Ensemble Participation', 'description' => 'Accepted members of an Ensemble, with contact info.'],
                ['route' => 'events.versions.tab-room.reports.student-seniority', 'name' => 'Student Seniority', 'description' => "An Ensemble's accepted members across every prior season."],
            ];
        @endphp

        @foreach ($reports as $report)
            <flux:card class="hover:border-zinc-300 dark:hover:border-zinc-600">
                <a href="{{ route($report['route'], $version) }}" wire:navigate class="block">
                    <flux:heading size="base">{{ $report['name'] }}</flux:heading>
                    <flux:text size="sm" class="text-zinc-500 mt-1">{{ $report['description'] }}</flux:text>
                </a>
            </flux:card>
        @endforeach
    </div>
</div>
