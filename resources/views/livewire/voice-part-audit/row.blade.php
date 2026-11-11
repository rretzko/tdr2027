@php
    /** @var \App\Models\Candidate $candidate */
    $candidate = $row->candidate;
    $student = $candidate->student->user;
    $teacher = $candidate->teacher?->user;
    $statusRaw = (string) $candidate->getRawOriginal('status');
    $statusLabel = \App\Enums\CandidateStatus::tryFrom($statusRaw)?->label() ?? $statusRaw;
@endphp

@if ($layout === 'card')
    <flux:card size="sm" wire:key="card-{{ $candidate->id }}">
        <div class="mb-2 flex items-start justify-between gap-3">
            <div class="min-w-0">
                <flux:heading size="base">{{ $student->last_name }}, {{ $student->first_name }}</flux:heading>
                <flux:text size="sm" class="text-zinc-500">
                    #{{ $candidate->id }} · Grade {{ $row->grade ?? '—' }} · {{ $teacher ? "{$teacher->last_name}, {$teacher->first_name}" : '—' }}
                </flux:text>
            </div>
            <flux:badge size="sm">{{ $statusLabel }}</flux:badge>
        </div>
        <div class="mb-3">
            @include('livewire.voice-part-audit.select')
        </div>
        @include('livewire.voice-part-audit.recordings')
    </flux:card>
@else
    <flux:table.row wire:key="row-{{ $candidate->id }}">
        <flux:table.cell class="align-top font-mono text-sm">{{ $candidate->id }}</flux:table.cell>
        <flux:table.cell class="align-top">
            <div class="font-medium">{{ $student->last_name }}, {{ $student->first_name }}</div>
            <div class="text-xs text-zinc-500 dark:text-zinc-400">Grade {{ $row->grade ?? '—' }} · {{ $statusLabel }}</div>
        </flux:table.cell>
        <flux:table.cell class="align-top">
            <div>{{ $teacher ? "{$teacher->last_name}, {$teacher->first_name}" : '—' }}</div>
            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $candidate->school->name ?? '' }}</div>
        </flux:table.cell>
        <flux:table.cell class="align-top min-w-48">
            @include('livewire.voice-part-audit.select')
        </flux:table.cell>
        <flux:table.cell class="align-top w-full">
            @include('livewire.voice-part-audit.recordings')
        </flux:table.cell>
    </flux:table.row>
@endif
