<div>
    <flux:heading size="xl" class="mb-6">Results</flux:heading>

    @if ($items->isEmpty())
        <flux:callout variant="info" icon="information-circle">
            <flux:callout.text>
                No closed events with candidates yet.
            </flux:callout.text>
        </flux:callout>
    @else
        <div class="space-y-3">
            @foreach ($items as $item)
                @php
                    /** @var \App\Models\Version $version */
                    $version = $item['version'];
                    /** @var int $candidateCount */
                    $candidateCount = $item['candidateCount'];
                @endphp
                <flux:card size="sm">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <flux:heading size="base">{{ $version->name }}</flux:heading>
                            <flux:text size="sm" class="text-zinc-500">{{ $version->event->name }}</flux:text>
                        </div>

                        <div class="flex items-center gap-3 shrink-0">
                            <flux:badge color="blue">{{ $candidateCount }} {{ Str::plural('candidate', $candidateCount) }}</flux:badge>
                            <flux:badge color="red" size="sm">Closed</flux:badge>
                            <flux:button size="sm" variant="primary" :href="route('registrations.results', $version)" wire:navigate>
                                Results
                            </flux:button>
                        </div>
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif
</div>
