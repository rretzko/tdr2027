<div>
    <flux:heading size="xl" class="mb-6">My Events</flux:heading>

    @if ($candidates->isEmpty())
        <flux:callout variant="info" icon="information-circle">
            <flux:callout.text>
                You don't have any open Events yet. Once your teacher is invited to an Event you're eligible for, it will show up here automatically.
            </flux:callout.text>
        </flux:callout>
    @else
        <div class="space-y-3">
            @foreach ($candidates as $candidate)
                @php
                    $rawStatus = $candidate->getRawOriginal('status');
                    $statusColor = match ($rawStatus) {
                        'eligible' => 'zinc',
                        'pending' => 'amber',
                        'registered' => 'green',
                        'withdrew', 'teacher_withdrawn' => 'red',
                        default => 'zinc',
                    };
                    $statusLabel = $candidate->status->label();
                @endphp
                <a href="{{ route('sfdi.events.candidate', $candidate) }}" wire:navigate class="block transition hover:shadow-md">
                    <flux:card size="sm">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <div>
                                <flux:heading size="base">{{ $candidate->version->name }}</flux:heading>
                                <flux:text size="sm" class="text-zinc-500">{{ $candidate->version->event->name }}</flux:text>
                            </div>

                            <div class="flex items-center gap-3 shrink-0">
                                <flux:badge :color="$statusColor" size="sm">{{ $statusLabel }}</flux:badge>
                                <flux:button size="sm" icon-trailing="chevron-right">View</flux:button>
                            </div>
                        </div>
                    </flux:card>
                </a>
            @endforeach
        </div>
    @endif
</div>
