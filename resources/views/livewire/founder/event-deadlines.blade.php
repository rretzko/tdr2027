<div>
    <div class="mb-6">
        <flux:heading size="xl">Event Deadlines</flux:heading>
        <flux:subheading>Every active and sandbox event version, next deadline first.</flux:subheading>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        @forelse ($events as $event)
            <flux:card class="flex flex-col gap-4">
                <flux:heading size="lg">{{ $event->name }}</flux:heading>

                <div class="flex flex-col gap-4">
                    @foreach ($event->versions as $version)
                        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-900/40">
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <flux:text class="font-medium">{{ $version->name }}</flux:text>
                                    <flux:badge color="{{ $version->status->value === 'active' ? 'green' : 'amber' }}" size="sm">
                                        {{ $version->status->label() }}
                                    </flux:badge>
                                </div>
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="pencil"
                                    :href="route('events.versions.edit', ['version' => $version->id, 'tab' => 'dates'])"
                                    wire:navigate
                                >
                                    Edit
                                </flux:button>
                            </div>

                            @if ($version->dates->isEmpty())
                                <flux:text size="sm" class="text-zinc-500">No dates configured yet.</flux:text>
                            @else
                                <ul class="flex flex-col gap-1.5">
                                    @foreach ($version->dates as $date)
                                        @php
                                            $isPast = ($date->end_at ?? $date->start_at)->isPast();
                                            $isNext = $loop->first && ! $isPast;
                                        @endphp
                                        <li class="flex items-center justify-between gap-3 text-sm {{ $isPast ? 'text-zinc-400 dark:text-zinc-500' : 'text-zinc-800 dark:text-zinc-100' }}">
                                            <span class="flex items-center gap-2">
                                                {{ $date->date_type->label() }}
                                                @if ($isNext)
                                                    <flux:badge color="blue" size="sm">Next</flux:badge>
                                                @endif
                                            </span>
                                            <span class="{{ $isNext ? 'font-semibold' : '' }} whitespace-nowrap">
                                                {{ $date->start_at->forDisplay()->format('M j, Y g:i A') }}
                                                @if ($date->end_at)
                                                    &ndash; {{ $date->end_at->forDisplay()->format('M j, Y g:i A') }}
                                                @endif
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            <div class="mt-2 flex items-center justify-between gap-3 border-t border-zinc-200 pt-2 text-sm text-zinc-800 dark:border-zinc-700 dark:text-zinc-100">
                                <span>Registrant Count:</span>
                                <span class="font-semibold">{{ number_format($version->registrant_count) }}</span>
                            </div>

                            <div class="mt-1.5 flex items-center justify-between gap-3 text-sm text-zinc-800 dark:text-zinc-100">
                                <span>Accepted:</span>
                                <span class="font-semibold">{{ number_format($accepted[$version->id]['total']) }}</span>
                            </div>
                            @if ($accepted[$version->id]['ensembles'] === [])
                                <flux:text size="sm" class="mt-1 pl-4 text-zinc-500">No ensembles configured yet.</flux:text>
                            @else
                                <ul class="mt-1 flex flex-col gap-1 pl-4">
                                    @foreach ($accepted[$version->id]['ensembles'] as $ensemble)
                                        <li class="flex items-center justify-between gap-3 text-sm text-zinc-600 dark:text-zinc-300">
                                            <span>{{ $ensemble['name'] }}</span>
                                            <span>{{ number_format($ensemble['count']) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @empty
            <flux:text class="text-zinc-500">No active or sandbox event versions found.</flux:text>
        @endforelse
    </div>
</div>
