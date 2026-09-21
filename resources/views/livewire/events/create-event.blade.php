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

        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 space-y-4">
            <div>
                <flux:heading size="sm">Event Managers</flux:heading>
                <flux:text size="sm" class="text-zinc-500">
                    You'll automatically be the Event Manager. Add any other teachers who should help manage this
                    event.
                </flux:text>
            </div>

            @if ($selectedEventManagers->isNotEmpty())
                <div class="flex flex-col gap-2">
                    @foreach ($selectedEventManagers as $manager)
                        <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div>
                                <flux:text class="font-medium">{{ $manager->name }}</flux:text>
                                <flux:text size="sm" class="text-zinc-500">{{ $manager->email }}</flux:text>
                            </div>
                            <flux:button size="sm" icon="x-mark" wire:click="removeEventManager({{ $manager->id }})" type="button" />
                        </div>
                    @endforeach
                </div>
            @endif

            <flux:field>
                <flux:label>Add a Teacher</flux:label>
                <flux:input wire:model.live.debounce.300ms="event_manager_search" placeholder="Search by name..." />
            </flux:field>

            @if ($eventManagerSearchResults->isNotEmpty())
                <div class="flex flex-col gap-2">
                    @foreach ($eventManagerSearchResults as $user)
                        <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div>
                                <flux:text class="font-medium">{{ $user->name }}</flux:text>
                                <flux:text size="sm" class="text-zinc-500">{{ $user->email }}</flux:text>
                            </div>
                            <flux:button size="sm" wire:click="addEventManager({{ $user->id }})" type="button">Add</flux:button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

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
