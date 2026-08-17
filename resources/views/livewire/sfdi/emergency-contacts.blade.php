<div class="flex flex-col gap-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Emergency Contacts</flux:heading>
        <flux:button size="sm" icon="plus" wire:click="addEmergencyContact">Add</flux:button>
    </div>

    @if ($contacts->isEmpty())
        <flux:card>
            <flux:text class="text-zinc-500">No emergency contacts yet.</flux:text>
        </flux:card>
    @else
        <div class="flex flex-col gap-4">
            @foreach ($contacts as $ec)
                <flux:card class="flex items-start justify-between gap-3">
                    <div>
                        <flux:text class="font-medium">{{ $ec->name }}</flux:text>
                        <flux:text size="sm" class="text-zinc-500">{{ $ec->relationship?->label() }}</flux:text>
                        @if ($ec->cell_phone)
                            <flux:text size="sm" class="text-zinc-500">Cell: {{ $ec->cell_phone }}</flux:text>
                        @endif
                        @if ($ec->work_phone)
                            <flux:text size="sm" class="text-zinc-500">Work: {{ $ec->work_phone }}</flux:text>
                        @endif
                        @if ($ec->home_phone)
                            <flux:text size="sm" class="text-zinc-500">Home: {{ $ec->home_phone }}</flux:text>
                        @endif
                        @if ($ec->email)
                            <flux:text size="sm" class="text-zinc-500">{{ $ec->email }}</flux:text>
                        @endif
                    </div>

                    <div class="flex gap-2">
                        <flux:button size="sm" variant="ghost" icon="pencil" wire:click="editEmergencyContact({{ $ec->id }})">Edit</flux:button>
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="remove({{ $ec->id }})" wire:confirm="Remove this emergency contact?">Remove</flux:button>
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif

    <flux:modal name="emergency-contact" class="w-full max-w-md">
        <div class="space-y-4">
            <flux:heading>{{ $editingEmergencyContactId !== null ? 'Edit Emergency Contact' : 'Add Emergency Contact' }}</flux:heading>

            <flux:field>
                <flux:label>Name</flux:label>
                <flux:input wire:model="ec_name" placeholder="Full name" />
                <flux:error name="ec_name" />
            </flux:field>

            <flux:field>
                <flux:label>Relationship</flux:label>
                <flux:select wire:model="ec_relationship">
                    <flux:select.option value="">— select —</flux:select.option>
                    @foreach ($relationships as $rel)
                        <flux:select.option value="{{ $rel->value }}">{{ $rel->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="ec_relationship" />
            </flux:field>

            <flux:field>
                <flux:label>Cell Phone (optional)</flux:label>
                <flux:input wire:model="ec_cell_phone" placeholder="(555) 000-0000" />
                <flux:error name="ec_cell_phone" />
            </flux:field>

            <flux:field>
                <flux:label>Work Phone (optional)</flux:label>
                <flux:input wire:model="ec_work_phone" placeholder="(555) 000-0000" />
                <flux:error name="ec_work_phone" />
            </flux:field>

            <flux:field>
                <flux:label>Home Phone (optional)</flux:label>
                <flux:input wire:model="ec_home_phone" placeholder="(555) 000-0000" />
                <flux:error name="ec_home_phone" />
            </flux:field>

            <flux:field>
                <flux:label>Email (optional)</flux:label>
                <flux:input wire:model="ec_email" type="email" placeholder="email@example.com" />
                <flux:error name="ec_email" />
            </flux:field>

            @if ($errors->any())
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>Please correct the errors above.</flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="save">{{ $editingEmergencyContactId !== null ? 'Save Changes' : 'Save Emergency Contact' }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
