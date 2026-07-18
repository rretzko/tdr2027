<div>
    <div class="mb-6">
        <a href="{{ route('dashboard') }}" wire:navigate class="text-sm text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
            &larr; Dashboard
        </a>
        <flux:heading size="xl" class="mt-1">Start a New Event</flux:heading>
    </div>

    <flux:callout icon="information-circle" class="mb-6">
        <flux:callout.text>
            Your event is created in Sandbox status so you can configure it before it goes live. You'll be its Event
            Manager and can invite others to help once it's set up.
        </flux:callout.text>
    </flux:callout>

    <div class="max-w-lg space-y-4">
        <flux:field>
            <flux:label>Event Name</flux:label>
            <flux:input wire:model="name" placeholder="e.g. All-State Chorus" />
            <flux:error name="name" />
        </flux:field>

        <flux:field>
            <flux:label>Short Name</flux:label>
            <flux:input wire:model="short_name" placeholder="e.g. All-State" />
            <flux:error name="short_name" />
        </flux:field>

        <flux:field>
            <flux:label>Organization</flux:label>
            <flux:select wire:model="organization_id">
                <flux:select.option value="">— select —</flux:select.option>
                @foreach ($organizations as $org)
                    <flux:select.option value="{{ $org->id }}">{{ $org->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="organization_id" />
        </flux:field>

        <flux:field>
            <flux:label>Frequency</flux:label>
            <flux:select wire:model="frequency">
                @foreach ($frequencies as $freq)
                    <flux:select.option value="{{ $freq->value }}">{{ $freq->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="frequency" />
        </flux:field>

        @if ($errors->any())
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.text>Please correct the errors above.</flux:callout.text>
            </flux:callout>
        @endif

        <div class="flex justify-end gap-3 pt-2">
            <flux:button variant="ghost" :href="route('dashboard')" wire:navigate>Cancel</flux:button>
            <flux:button variant="primary" wire:click="create">Create Event</flux:button>
        </div>
    </div>
</div>
