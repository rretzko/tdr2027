<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('registrations.index') }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Registrations</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Request Invitation</span>
    </div>

    @php $rawStatus = $existingRequest?->getRawOriginal('status'); @endphp

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">Request Invitation</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->name }}</flux:text>
        </div>

        @if ($rawStatus === 'pending')
            <flux:badge color="amber">Request sent</flux:badge>
        @elseif ($rawStatus === 'denied')
            <flux:badge color="red">Denied</flux:badge>
        @endif
    </div>

    <flux:card class="mb-6">
        <flux:text class="text-zinc-700 dark:text-zinc-300">
            You're eligible to register candidates for <strong>{{ $version->name }}</strong>, but you haven't been
            invited yet. Requesting an invitation notifies the Event Manager, who can approve or deny it.
        </flux:text>
    </flux:card>

    @if ($rawStatus === 'denied')
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-4">
            <flux:callout.text>
                Your previous request was denied. You're welcome to request again.
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <flux:button
            variant="primary"
            wire:click="request"
            :disabled="$rawStatus === 'pending'"
        >
            {{ $rawStatus === 'pending' ? 'Request Sent' : 'Request Invitation' }}
        </flux:button>
    </div>
</div>
