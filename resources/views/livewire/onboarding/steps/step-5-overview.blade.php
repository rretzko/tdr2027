<div class="flex flex-col gap-6">
    <flux:subheading>You're all set</flux:subheading>

    <flux:callout color="blue" icon="academic-cap" heading="Your roster">
        <flux:callout.text>
            Manage your students, add new ones, and track their progress from your dashboard at any time.
        </flux:callout.text>
    </flux:callout>

    <flux:callout color="blue" icon="building-library" heading="Your school">
        <flux:callout.text>
            You can update your school details or add a co-teacher later from your settings.
        </flux:callout.text>
    </flux:callout>

    <flux:callout color="blue" icon="calendar" heading="Events">
        <flux:callout.text>
            Once your school email is verified on the Schools page, look for events on the Registrations page, where you can request an invitation.
        </flux:callout.text>
    </flux:callout>

    <div class="flex items-center gap-4">
        <flux:button variant="ghost" wire:click="back">Back</flux:button>
        <flux:button variant="primary" wire:click="finish">Go to my dashboard</flux:button>
    </div>
</div>
