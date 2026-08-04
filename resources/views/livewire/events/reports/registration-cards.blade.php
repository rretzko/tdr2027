<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.reports', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Reports</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Registration Cards</span>
    </div>

    <div class="mb-6">
        <flux:heading size="xl">Registration Cards</flux:heading>
        <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — printable registration cards for in-person auditions</flux:text>
    </div>

    <flux:callout variant="info" icon="information-circle" class="mb-4">
        <flux:callout.text>No in-person audition is scheduled for the current season — the printed cards are placeholders.</flux:callout.text>
    </flux:callout>

    <div class="flex flex-col sm:flex-row gap-3 mb-6">
        <flux:input wire:model.live.debounce.300ms="candidateIdFilter" placeholder="Candidate id..." class="sm:max-w-2xs" />
        <flux:select wire:model.live="schoolFilter" placeholder="All schools" class="sm:max-w-2xs">
            <flux:select.option value="">All schools</flux:select.option>
            @foreach ($schoolOptions as $school)
                <flux:select.option value="{{ $school }}">{{ $school }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="voicePartFilter" placeholder="All voice parts" class="sm:max-w-2xs">
            <flux:select.option value="">All voice parts</flux:select.option>
            @foreach ($availableVoiceParts as $voicePart)
                <flux:select.option value="{{ $voicePart->id }}">{{ $voicePart->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:button variant="primary" icon="printer" :href="route('events.versions.reports.registration-cards.pdf', ['version' => $version, 'candidateIdFilter' => $candidateIdFilter, 'schoolFilter' => $schoolFilter, 'voicePartFilter' => $voicePartFilter])" target="_blank">
        Print Registration Cards
    </flux:button>
</div>
