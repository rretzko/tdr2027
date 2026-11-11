<div>
    @if ($standalone)
        <div class="mb-6">
            <flux:heading size="xl">Voice Part Audit</flux:heading>
            <flux:subheading>Listen to each candidate's teacher-approved recordings and confirm their voice part. Only voice parts offered for the student's grade can be chosen.</flux:subheading>
        </div>
    @else
        <flux:text size="sm" class="mb-4 text-zinc-500">
            Listen to each candidate's teacher-approved recordings and confirm their voice part. Only voice parts offered for the student's grade can be chosen.
        </flux:text>
    @endif

    <div @class(['mb-4 grid grid-cols-1 gap-3 md:grid-cols-2', 'lg:grid-cols-4' => $standalone, 'lg:grid-cols-3' => ! $standalone])>
        @if ($standalone)
            <flux:select wire:model.live="versionId" label="Version" placeholder="Select a version...">
                @foreach ($versions as $option)
                    <flux:select.option value="{{ $option->id }}">{{ $option->event->name }} — {{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <flux:select wire:model.live="status" label="Status">
            <flux:select.option value="{{ \App\Services\VoicePartAuditService::STATUS_ACTIVE }}">Active (Eligible/Pending/Registered)</flux:select.option>
            <flux:select.option value="{{ \App\Services\VoicePartAuditService::STATUS_ALL }}">All statuses</flux:select.option>
            @foreach ($statuses as $statusOption)
                <flux:select.option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="voicePartId" label="Voice part">
            <flux:select.option value="">All voice parts</flux:select.option>
            @foreach ($voiceParts as $voicePartOption)
                <flux:select.option value="{{ $voicePartOption->id }}">{{ $voicePartOption->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model.live.debounce.300ms="search" label="Search" placeholder="Student or teacher name…" icon="magnifying-glass" />
    </div>

    <div class="mb-6">
        <flux:switch wire:model.live="mismatchOnly" label="Grade mismatch only" description="Show only candidates whose voice part isn't offered for their grade." />
    </div>

    @if ($version === null)
        <flux:callout variant="info" icon="information-circle">
            <flux:callout.text>Select a version to list its candidates with approved recordings.</flux:callout.text>
        </flux:callout>
    @elseif ($rows->total() === 0)
        <flux:callout variant="info" icon="information-circle">
            <flux:callout.text>No candidates with approved recordings match these filters.</flux:callout.text>
        </flux:callout>
    @else
        <flux:text size="sm" class="mb-3 text-zinc-500">{{ $rows->total() }} {{ \Illuminate\Support\Str::plural('candidate', $rows->total()) }}</flux:text>

        {{-- Cards below md:, table at md:+ --}}
        <div class="md:hidden space-y-3">
            @foreach ($rows as $row)
                @include('livewire.voice-part-audit.row', ['row' => $row, 'layout' => 'card'])
            @endforeach
        </div>

        <flux:table class="hidden md:table">
            <flux:table.columns>
                <flux:table.column>Candidate</flux:table.column>
                <flux:table.column sortable :sorted="$sort === 'student'" :direction="$direction" wire:click="sortBy('student')">Student</flux:table.column>
                <flux:table.column sortable :sorted="$sort === 'teacher'" :direction="$direction" wire:click="sortBy('teacher')">Teacher</flux:table.column>
                <flux:table.column sortable :sorted="$sort === 'voice_part'" :direction="$direction" wire:click="sortBy('voice_part')">Voice part</flux:table.column>
                <flux:table.column>Approved recordings</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($rows as $row)
                    @include('livewire.voice-part-audit.row', ['row' => $row, 'layout' => 'table'])
                @endforeach
            </flux:table.rows>
        </flux:table>

        <div class="mt-4">
            {{ $rows->links() }}
        </div>
    @endif
</div>
