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
            <flux:callout.text>No registered candidates for this event.</flux:callout.text>
        </flux:callout>
    @else
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
                </flux:card>
            @endforeach
        </div>

        <flux:table class="hidden md:table">
            <flux:table.columns>
                <flux:table.column class="w-12">#</flux:table.column>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column align="center">Voice Part</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($candidates as $index => $candidate)
                    <flux:table.row>
                        <flux:table.cell class="tabular-nums text-zinc-500">{{ $index + 1 }}</flux:table.cell>
                        <flux:table.cell class="font-medium">{{ $candidate->student->user->sort_name }}</flux:table.cell>
                        <flux:table.cell align="center">{{ $candidate->voicePart?->abbr ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
