<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>{{ $version->name }}</span>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Reports</span>
    </div>

    <div class="mb-6">
        <flux:heading size="xl">Reports</flux:heading>
        <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — registration reports for your county assignment</flux:text>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @php
            $reports = [
                ['route' => 'events.versions.reports.obligated-teachers', 'name' => 'Obligated Teachers', 'description' => 'Teachers who have accepted the Version obligations.'],
                ['route' => 'events.versions.reports.participating-teachers', 'name' => 'Participating Teachers', 'description' => 'Teachers with registered candidates.'],
                ['route' => 'events.versions.reports.participating-schools', 'name' => 'Participating Schools', 'description' => 'Packet receipt and payment status by school.'],
                ['route' => 'events.versions.reports.payment-roster', 'name' => 'Payment Roster', 'description' => 'Every payment recorded for this Version.'],
                ['route' => 'events.versions.reports.participating-candidates', 'name' => 'Participating Candidates', 'description' => 'Registered candidates, with limited edit/remove.'],
                ['route' => 'events.versions.reports.participation-by-county', 'name' => 'Participation by County', 'description' => 'Counts by county, including counties with none.'],
                ['route' => 'events.versions.reports.candidate-counts', 'name' => 'Candidate Counts', 'description' => 'Counts by school, teacher, and voice part.'],
                ['route' => 'events.versions.reports.adjudication-backup', 'name' => 'Adjudication Backup', 'description' => 'Paper/CSV backups for in-person auditions.'],
                ['route' => 'events.versions.reports.registration-cards', 'name' => 'Registration Cards', 'description' => 'Printable registration cards for in-person auditions.'],
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
