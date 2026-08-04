<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.reports', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Reports</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Adjudication Backup</span>
    </div>

    <div class="mb-6">
        <flux:heading size="xl">Adjudication Backup</flux:heading>
        <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — paper/CSV backups for in-person auditions</flux:text>
    </div>

    <flux:callout variant="info" icon="information-circle" class="mb-4">
        <flux:callout.text>No in-person audition is scheduled for the current season — the files below are placeholders.</flux:callout.text>
    </flux:callout>

    @if ($rooms->isEmpty())
        <flux:callout variant="info" icon="magnifying-glass">
            <flux:callout.text>No rooms have been added to this Version yet.</flux:callout.text>
        </flux:callout>
    @else
        <div class="space-y-3">
            @foreach ($rooms as $room)
                <flux:card size="sm">
                    <div class="flex items-center justify-between gap-3">
                        <flux:heading size="base">{{ $room->name }}</flux:heading>
                        <div class="flex gap-2">
                            <flux:button size="sm" variant="outline" :href="route('events.versions.reports.adjudication-backup.export', ['version' => $version, 'type' => 'paper'])" target="_blank">
                                Paper
                            </flux:button>
                            <flux:button size="sm" variant="outline" :href="route('events.versions.reports.adjudication-backup.export', ['version' => $version, 'type' => 'csv'])">
                                CSV
                            </flux:button>
                            <flux:button size="sm" variant="outline" :href="route('events.versions.reports.adjudication-backup.export', ['version' => $version, 'type' => 'checklist'])" target="_blank">
                                Checklist
                            </flux:button>
                        </div>
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif
</div>
