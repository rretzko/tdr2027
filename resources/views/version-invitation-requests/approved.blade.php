<x-layouts.auth>
    <div class="flex flex-col gap-2 text-center">
        <flux:heading size="lg">Request approved</flux:heading>
        <flux:text class="text-zinc-500">
            {{ $versionInvitationRequest->teacher->user->name }} has been invited to {{ $versionInvitationRequest->version->name }}. You can close this page.
        </flux:text>
    </div>
</x-layouts.auth>
