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

            <div class="flex flex-col sm:flex-row gap-2">
                @if ($schoolOptions->count() > 1)
                    <flux:select wire:model.live="schoolFilter" placeholder="All schools" class="sm:max-w-xs">
                        <flux:select.option value="">All schools</flux:select.option>
                        @foreach ($schoolOptions as $school)
                            <flux:select.option value="{{ $school->id }}">{{ $school->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                @if ($switcherOptions->count() > 1)
                    <flux:select wire:model.live="switchVersionId" class="sm:max-w-xs">
                        @foreach ($switcherOptions as $option)
                            <flux:select.option value="{{ $option->id }}">{{ $option->event->name }} — {{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>
        </div>
    </div>

    <flux:callout variant="info" icon="information-circle">
        <flux:callout.text>Previous audition results will be available no later than Friday, September 4, 2026.</flux:callout.text>
    </flux:callout>
</div>
