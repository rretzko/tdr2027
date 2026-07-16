@php
    $teacherEmail = $versionInvitationRequest->teacher->user->email;
    $subject = rawurlencode("Your invitation request for {$versionInvitationRequest->version->name}");
    $body = rawurlencode("Hi {$versionInvitationRequest->teacher->user->first_name},\n\nWe are unable to approve your participation in {$versionInvitationRequest->version->name} at this time for the following reason(s):\n\n");
    $mailtoUrl = $teacherEmail !== null ? "mailto:{$teacherEmail}?subject={$subject}&body={$body}" : null;
@endphp

<x-layouts.auth>
    <div class="flex flex-col gap-3 text-center">
        <flux:heading size="lg">Request denied</flux:heading>
        <flux:text class="text-zinc-500">
            The request from {{ $versionInvitationRequest->teacher->user->name }} for {{ $versionInvitationRequest->version->name }} has been denied.
        </flux:text>

        @if ($mailtoUrl !== null)
            <flux:text class="text-zinc-500">
                No email was sent automatically. If you'd like to explain why, you can compose one now:
            </flux:text>
            <div>
                <flux:button :href="$mailtoUrl" variant="filled">Compose explanation email</flux:button>
            </div>
        @endif

        <flux:text size="sm" class="text-zinc-400">You can close this page.</flux:text>
    </div>
</x-layouts.auth>
