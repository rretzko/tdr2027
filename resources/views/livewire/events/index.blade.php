<div>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <flux:heading size="xl">Events</flux:heading>

        <flux:modal.trigger name="edit-event">
            <flux:button variant="primary" icon="plus" wire:click="add">
                Add event
            </flux:button>
        </flux:modal.trigger>
    </div>

    {{-- Cards below md:, full table at md:+ --}}
    <div class="md:hidden space-y-3">
        @forelse ($events as $event)
            <flux:card size="sm">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <flux:heading size="base" class="truncate">{{ $event->name }}</flux:heading>
                        <flux:text size="sm" class="text-zinc-500">{{ $event->organization->name }}</flux:text>
                        <flux:text size="sm" class="text-zinc-400">{{ $event->getRawOriginal('frequency') }}</flux:text>
                    </div>

                    <div class="flex flex-col items-end gap-2 shrink-0">
                        @php $raw = $event->getRawOriginal('status'); @endphp
                        @if ($raw === 'active')
                            <flux:badge color="green" size="sm">Active</flux:badge>
                        @elseif ($raw === 'sandbox')
                            <flux:badge color="amber" size="sm">Sandbox</flux:badge>
                        @elseif ($raw === 'inactive')
                            <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                        @else
                            <flux:badge color="red" size="sm">Closed</flux:badge>
                        @endif

                        <div class="flex gap-2">
                            <flux:button size="sm" :href="route('events.show', $event)" wire:navigate>
                                Versions
                            </flux:button>
                            <flux:modal.trigger name="edit-event">
                                <flux:button size="sm" variant="ghost" icon="pencil" wire:click="edit({{ $event->id }})" />
                            </flux:modal.trigger>
                        </div>
                    </div>
                </div>
            </flux:card>
        @empty
            <flux:text class="text-zinc-500 py-4 text-center">No events yet. Add one to get started.</flux:text>
        @endforelse
    </div>

    <flux:table class="hidden md:table">
        <flux:table.columns>
            <flux:table.column>Event</flux:table.column>
            <flux:table.column>Organization</flux:table.column>
            <flux:table.column>Frequency</flux:table.column>
            <flux:table.column>Auditions</flux:table.column>
            <flux:table.column>Ensembles</flux:table.column>
            <flux:table.column>Status</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($events as $event)
                <flux:table.row>
                    <flux:table.cell class="font-medium">
                        {{ $event->name }}
                        @if ($event->short_name)
                            <flux:text size="sm" class="text-zinc-400">{{ $event->short_name }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $event->organization->name }}</flux:table.cell>
                    <flux:table.cell class="capitalize">{{ $event->getRawOriginal('frequency') }}</flux:table.cell>
                    <flux:table.cell>{{ $event->audition_count }}</flux:table.cell>
                    <flux:table.cell>{{ $event->ensemble_count }}</flux:table.cell>
                    <flux:table.cell>
                        @php $raw = $event->getRawOriginal('status'); @endphp
                        @if ($raw === 'active')
                            <flux:badge color="green" size="sm">Active</flux:badge>
                        @elseif ($raw === 'sandbox')
                            <flux:badge color="amber" size="sm">Sandbox</flux:badge>
                        @elseif ($raw === 'inactive')
                            <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                        @else
                            <flux:badge color="red" size="sm">Closed</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-2 justify-end">
                            <flux:button size="sm" :href="route('events.show', $event)" wire:navigate>
                                Versions
                            </flux:button>
                            <flux:modal.trigger name="edit-event">
                                <flux:button size="sm" variant="ghost" icon="pencil" wire:click="edit({{ $event->id }})" />
                            </flux:modal.trigger>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="text-center text-zinc-500 py-6">
                        No events yet. Add one to get started.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="edit-event" class="w-full max-w-lg">
        <flux:heading size="lg" class="mb-4">
            {{ $editingEventId ? 'Edit Event' : 'Add Event' }}
        </flux:heading>

        <div class="space-y-4">
            <flux:field>
                <flux:label>Event Name</flux:label>
                <flux:input wire:model="edit_name" placeholder="e.g. All-State Chorus" />
                <flux:error name="edit_name" />
            </flux:field>

            <flux:field>
                <flux:label>Short Name</flux:label>
                <flux:input wire:model="edit_short_name" placeholder="e.g. All-State" />
                <flux:error name="edit_short_name" />
            </flux:field>

            <flux:field>
                <flux:label>Organization</flux:label>
                <flux:select wire:model="edit_organization_id">
                    <flux:select.option value="">— select —</flux:select.option>
                    @foreach ($organizations as $org)
                        <flux:select.option value="{{ $org->id }}">{{ $org->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="edit_organization_id" />
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Status</flux:label>
                    <flux:select wire:model="edit_status">
                        @foreach ($statuses as $status)
                            <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="edit_status" />
                </flux:field>

                <flux:field>
                    <flux:label>Frequency</flux:label>
                    <flux:select wire:model="edit_frequency">
                        @foreach ($frequencies as $freq)
                            <flux:select.option value="{{ $freq->value }}">{{ $freq->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="edit_frequency" />
                </flux:field>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Audition Count</flux:label>
                    <flux:input wire:model="edit_audition_count" type="number" min="1" max="10" />
                    <flux:error name="edit_audition_count" />
                </flux:field>

                <flux:field>
                    <flux:label>Ensemble Count</flux:label>
                    <flux:input wire:model="edit_ensemble_count" type="number" min="1" max="20" />
                    <flux:error name="edit_ensemble_count" />
                </flux:field>
            </div>
        </div>

        @if ($errors->any())
            <flux:callout variant="danger" icon="exclamation-triangle" class="mt-4">
                <flux:callout.text>Please correct the errors above.</flux:callout.text>
            </flux:callout>
        @endif

        <div class="flex justify-end gap-3 mt-6">
            <flux:modal.close>
                <flux:button variant="ghost">Cancel</flux:button>
            </flux:modal.close>
            <flux:button variant="primary" wire:click="save">
                {{ $editingEventId ? 'Save Changes' : 'Create Event' }}
            </flux:button>
        </div>
    </flux:modal>
</div>
