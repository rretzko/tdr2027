<div class="flex flex-col gap-6">
    <flux:subheading>Open events from your organizations</flux:subheading>

    @if ($openEvents->isEmpty())
        <flux:callout color="zinc" icon="information-circle" heading="No open events right now">
            <flux:callout.text>
                There aren't any open event invitations available at the moment. You'll be able to request one later from your dashboard.
            </flux:callout.text>
        </flux:callout>
    @else
        <flux:checkbox.group wire:model="selectedEventIds" label="Request an invitation to:">
            @foreach ($openEvents as $event)
                <flux:checkbox value="{{ $event->id }}" label="{{ $event->name }}" />
            @endforeach
        </flux:checkbox.group>
    @endif

    <div class="flex items-center gap-4">
        <flux:button variant="ghost" wire:click="back">Back</flux:button>
        <flux:button variant="primary" wire:click="requestEventInvitations">Continue</flux:button>
    </div>
</div>
