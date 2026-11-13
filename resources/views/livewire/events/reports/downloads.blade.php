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
    </div>
</div>
