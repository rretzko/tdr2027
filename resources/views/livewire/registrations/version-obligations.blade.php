<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('registrations.index') }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Registrations</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('registrations.version', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Obligations</span>
    </div>

    @php $rawDecision = $response?->getRawOriginal('decision'); @endphp

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">Teacher Obligations</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->name }}</flux:text>
        </div>

        @if ($rawDecision === 'accepted')
            <flux:badge color="green">Accepted</flux:badge>
        @elseif ($rawDecision === 'rejected')
            <flux:badge color="red">Rejected</flux:badge>
        @else
            <flux:badge color="amber">Awaiting your response</flux:badge>
        @endif
    </div>

    @if ($obligation === null)
        <flux:callout
            variant="secondary"
            icon="information-circle"
            heading="Not yet published"
            text="The Event Manager hasn't published Teacher Obligations for this Version yet. Check back later."
        />
    @else
        <flux:card class="mb-6">
            <div class="obligation-content text-zinc-700 dark:text-zinc-300
                [&_ul]:list-disc [&_ul]:list-outside [&_ul]:pl-6 [&_ul_ul]:list-[circle] [&_ul_ul]:mt-1
                [&_ol]:list-decimal [&_ol]:list-outside [&_ol]:pl-6
                [&_li]:mb-2 [&_strong]:font-semibold [&_u]:underline [&_p]:mb-3">
                {!! $body !!}
            </div>
        </flux:card>

        @if ($response !== null)
            <flux:text size="sm" class="text-zinc-500 mb-4">
                You {{ $rawDecision }} these obligations on {{ $response->decided_at->format('M j, Y \a\t g:ia') }}.
                You may change your response below.
            </flux:text>
        @endif

        <div class="flex flex-wrap items-center gap-3 mb-2">
            <flux:button
                variant="{{ $rawDecision === 'accepted' ? 'primary' : 'filled' }}"
                wire:click="accept"
                wire:confirm="Accept these obligations?"
            >
                Accept Obligations
            </flux:button>

            <flux:button
                variant="{{ $rawDecision === 'rejected' ? 'danger' : 'filled' }}"
                wire:click="reject"
                wire:confirm="Reject these obligations? You can change your response later."
            >
                Reject Obligations
            </flux:button>

            <flux:button variant="ghost" icon="arrow-down-tray" disabled>
                Download PDF
            </flux:button>
        </div>
        <flux:text size="xs" class="text-zinc-400">PDF export isn't wired up yet.</flux:text>
    @endif
</div>
