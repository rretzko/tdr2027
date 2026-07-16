@php
    $rawStatus = $versionInvitationRequest->getRawOriginal('status');
    $decidedByName = $versionInvitationRequest->decidedBy?->name ?? 'another Event Manager';
    $decidedAt = $versionInvitationRequest->decided_at?->format('M j, Y \a\t g:ia');
@endphp

<x-layouts.auth>
    <div class="flex flex-col gap-2 text-center">
        <flux:heading size="lg">Already handled</flux:heading>
        <flux:text class="text-zinc-500">
            This request from {{ $versionInvitationRequest->teacher->user->name }} was already {{ $rawStatus }} by {{ $decidedByName }}
            @if ($decidedAt) on {{ $decidedAt }} @endif.
            You can close this page.
        </flux:text>
    </div>
</x-layouts.auth>
