<div>
    <div class="mb-6">
        <a href="{{ route('registrations.results-index') }}" wire:navigate class="text-sm text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
            &larr; Results
        </a>

        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mt-1">
            <div>
                <flux:heading size="xl">{{ $version->name }}</flux:heading>
                <flux:text size="sm" class="text-zinc-500">{{ $version->event->name }}</flux:text>
            </div>

            @if ($switcherOptions->count() > 1)
                <flux:select wire:model.live="switchVersionId" class="sm:max-w-xs">
                    @foreach ($switcherOptions as $option)
                        <flux:select.option value="{{ $option->id }}">{{ $option->event->name }} — {{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </div>
    </div>

    @if ($candidates->isEmpty())
        <flux:callout variant="info" icon="information-circle">
            <flux:callout.text>No audition results for this event yet.</flux:callout.text>
        </flux:callout>
    @else
        @php
            $accepted = fn ($candidate) => $candidate->getRawOriginal('status') === 'accepted';
        @endphp

        {{-- Cards below md:, table at md:+ --}}
        <div class="md:hidden space-y-3">
            @foreach ($candidates as $index => $candidate)
                <flux:card size="sm">
                    <div class="flex items-center gap-3">
                        <flux:text class="text-zinc-500 tabular-nums">{{ $index + 1 }}</flux:text>
                        <flux:heading size="base">{{ $candidate->student->user->sort_name }}</flux:heading>
                        <flux:spacer />
                        <flux:badge size="sm">{{ $candidate->voicePart?->abbr ?? '—' }}</flux:badge>
                    </div>
                    <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                        <div>
                            <flux:text size="sm" class="text-zinc-500">Score</flux:text>
                            <flux:text>{{ $candidate->auditionResult?->total ?? '—' }}</flux:text>
                        </div>
                        <div>
                            <flux:text size="sm" class="text-zinc-500">Result</flux:text>
                            @if ($accepted($candidate))
                                <flux:badge size="sm" color="green">Accepted</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ $candidate->status->label() }}</flux:badge>
                            @endif
                        </div>
                        <div>
                            <flux:text size="sm" class="text-zinc-500">Ensemble</flux:text>
                            <flux:text>{{ $candidate->acceptedEnsemble?->name ?? '—' }}</flux:text>
                        </div>
                    </div>
                </flux:card>
            @endforeach
        </div>

        <flux:table class="hidden md:table">
            <flux:table.columns>
                <flux:table.column class="w-12">#</flux:table.column>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column align="center">Voice Part</flux:table.column>
                <flux:table.column align="center">Score</flux:table.column>
                <flux:table.column align="center">Result</flux:table.column>
                <flux:table.column align="center">Ensemble</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($candidates as $index => $candidate)
                    <flux:table.row>
                        <flux:table.cell class="tabular-nums text-zinc-500">{{ $index + 1 }}</flux:table.cell>
                        <flux:table.cell class="font-medium">{{ $candidate->student->user->sort_name }}</flux:table.cell>
                        <flux:table.cell align="center">{{ $candidate->voicePart?->abbr ?? '—' }}</flux:table.cell>
                        <flux:table.cell align="center" class="tabular-nums">{{ $candidate->auditionResult?->total ?? '—' }}</flux:table.cell>
                        <flux:table.cell align="center">
                            @if ($accepted($candidate))
                                <flux:badge size="sm" color="green">Accepted</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ $candidate->status->label() }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="center">{{ $candidate->acceptedEnsemble?->name ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
