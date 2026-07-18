<div>
    <flux:heading size="xl" class="mb-6">Registrations</flux:heading>

    @if ($sections['open']->isEmpty() && $sections['eligible']->isEmpty() && $sections['active']->isEmpty())
        <flux:callout variant="info" icon="information-circle">
            <flux:callout.text>
                No events are currently open for registration and you have no active candidates.
                Check back when an Event Manager opens a registration window.
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($sections['open']->isNotEmpty())
        <flux:heading size="lg" class="mb-3">Open for Registration</flux:heading>

        <div class="space-y-3 mb-8">
            @foreach ($sections['open'] as $item)
                @php
                    /** @var \App\Models\Version $version */
                    $version = $item['version'];
                    /** @var int $candidateCount */
                    $candidateCount = $item['candidateCount'];
                    /** @var \App\Models\VersionDate|null $nextDate */
                    $nextDate = $item['nextDate'];
                @endphp
                <flux:card size="sm">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <flux:heading size="base">{{ $version->name }}</flux:heading>
                            <flux:text size="sm" class="text-zinc-500">{{ $version->event->name }}</flux:text>

                            @if ($nextDate)
                                <flux:text size="sm" class="text-zinc-400 mt-1">
                                    Next: {{ $nextDate->dateType?->label() ?? $nextDate->getRawOriginal('date_type') }}
                                    — {{ \Carbon\Carbon::parse($nextDate->getRawOriginal('start_at'))->format('M j, Y') }}
                                </flux:text>
                            @endif
                        </div>

                        <div class="flex items-center gap-3 shrink-0">
                            @if ($candidateCount > 0)
                                <flux:badge color="blue">{{ $candidateCount }} {{ Str::plural('candidate', $candidateCount) }}</flux:badge>
                            @endif
                            <flux:badge color="green" size="sm">Active</flux:badge>
                            <flux:button size="sm" variant="primary" :href="route('registrations.version', $version)" wire:navigate>
                                Manage
                            </flux:button>
                        </div>
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif

    @if ($sections['eligible']->isNotEmpty())
        <flux:heading size="lg" class="mb-1">Invitation Available</flux:heading>
        <flux:text size="sm" class="text-zinc-500 mb-3">
            You're eligible to register candidates for these — request an invitation to get started.
        </flux:text>

        <div class="space-y-3 mb-8">
            @foreach ($sections['eligible'] as $item)
                @php
                    /** @var \App\Models\Version $version */
                    $version = $item['version'];
                    /** @var \App\Models\VersionDate|null $nextDate */
                    $nextDate = $item['nextDate'];
                @endphp
                <flux:card size="sm">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <flux:heading size="base">{{ $version->name }}</flux:heading>
                            <flux:text size="sm" class="text-zinc-500">{{ $version->event->name }}</flux:text>

                            @if ($nextDate)
                                <flux:text size="sm" class="text-zinc-400 mt-1">
                                    Next: {{ $nextDate->dateType?->label() ?? $nextDate->getRawOriginal('date_type') }}
                                    — {{ \Carbon\Carbon::parse($nextDate->getRawOriginal('start_at'))->format('M j, Y') }}
                                </flux:text>
                            @endif
                        </div>

                        <div class="flex items-center gap-3 shrink-0">
                            <flux:badge color="green" size="sm">Active</flux:badge>
                            <flux:badge color="amber" size="sm">Not Invited</flux:badge>
                            <flux:button size="sm" variant="primary" :href="route('registrations.request-invitation', $version)" wire:navigate>
                                Request Invitation
                            </flux:button>
                        </div>
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif

    @if ($sections['active']->isNotEmpty())
        <flux:heading size="lg" class="mb-3">Active Candidates</flux:heading>

        <div class="space-y-3">
            @foreach ($sections['active'] as $item)
                @php
                    /** @var \App\Models\Version $version */
                    $version = $item['version'];
                    /** @var int $candidateCount */
                    $candidateCount = $item['candidateCount'];
                    /** @var \App\Models\VersionDate|null $nextDate */
                    $nextDate = $item['nextDate'];
                @endphp
                <flux:card size="sm">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <flux:heading size="base">{{ $version->name }}</flux:heading>
                            <flux:text size="sm" class="text-zinc-500">{{ $version->event->name }}</flux:text>

                            @if ($nextDate)
                                <flux:text size="sm" class="text-zinc-400 mt-1">
                                    Next: {{ $nextDate->dateType?->label() ?? $nextDate->getRawOriginal('date_type') }}
                                    — {{ \Carbon\Carbon::parse($nextDate->getRawOriginal('start_at'))->format('M j, Y') }}
                                </flux:text>
                            @endif
                        </div>

                        <div class="flex items-center gap-3 shrink-0">
                            <flux:badge color="blue">{{ $candidateCount }} {{ Str::plural('candidate', $candidateCount) }}</flux:badge>
                            <flux:badge color="green" size="sm">Active</flux:badge>
                            <flux:button size="sm" :href="route('registrations.version', $version)" wire:navigate>
                                View
                            </flux:button>
                        </div>
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif
</div>
