<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.reports', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->name }} Reports</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Downloads</span>
    </div>

    <div class="mb-6">
        <flux:heading size="xl">Downloads</flux:heading>
        <flux:text size="sm" class="text-zinc-500">Download your event's data as CSV files.</flux:text>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach ($downloads as $download)
            <flux:card class="flex flex-col justify-between gap-4">
                <div>
                    <flux:heading size="base">{{ $download['name'] }}</flux:heading>
                    <flux:text size="sm" class="text-zinc-500 mt-1">{{ $download['description'] }}</flux:text>
                </div>
                <div>
                    <flux:button
                        size="sm"
                        icon="arrow-down-tray"
                        href="{{ route('events.versions.reports.downloads.export', ['version' => $version, 'topic' => $download['topic']]) }}"
                    >
                        Download CSV
                    </flux:button>
                </div>
            </flux:card>
        @endforeach

        {{-- One stored CSV per Ensemble of its accepted candidates, built at Close Audition. --}}
        <flux:card class="flex flex-col justify-between gap-4 sm:col-span-2 lg:col-span-3">
            <div>
                <flux:heading size="base">Ensemble Participants</flux:heading>
                <flux:text size="sm" class="text-zinc-500 mt-1">
                    One file per ensemble listing its accepted candidates with school, teacher, voice part, score and contact details, including up to three emergency contacts.
                    The files are built when the audition is closed. Regenerate them after a decline or a corrected detail.
                </flux:text>
            </div>

            <div class="flex flex-col gap-2">
                @foreach ($ensembleParticipants as $item)
                    <div wire:key="ensemble-participants-{{ $item['ensemble']->id }}" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 dark:border-zinc-700 px-3 py-2">
                        <div>
                            <flux:text class="font-medium">{{ $item['ensemble']->name }}</flux:text>
                            @if ($item['export'] !== null)
                                <flux:text size="sm" class="text-zinc-500">
                                    {{ $item['export']->row_count }} accepted · generated {{ $item['export']->generated_at->forDisplay()->format('M j, Y 	 g:i a') }}
                                </flux:text>
                            @else
                                <flux:text size="sm" class="text-zinc-500">Created when the audition is closed.</flux:text>
                            @endif
                        </div>
                        @if ($item['export'] !== null)
                            <flux:button
                                size="sm"
                                icon="arrow-down-tray"
                                href="{{ route('events.versions.reports.downloads.ensemble-participants', ['version' => $version, 'ensemble' => $item['ensemble']]) }}"
                            >
                                Download CSV
                            </flux:button>
                        @endif
                    </div>
                @endforeach
            </div>

            <div>
                <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="regenerateEnsembleParticipants" wire:loading.attr="disabled" wire:target="regenerateEnsembleParticipants">
                    Regenerate
                </flux:button>
            </div>
        </flux:card>
    </div>
</div>
