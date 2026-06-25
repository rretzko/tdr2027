<div>
    <div class="mb-6 flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">Trackable Pages</flux:heading>
            <flux:subheading>Manage which pages appear in teachers' Fast Pass dropdown.</flux:subheading>
        </div>
        <flux:button icon="plus" variant="primary" wire:click="add">Add Page</flux:button>
    </div>

    {{-- Mobile: card list --}}
    <div class="flex flex-col gap-3 md:hidden">
        @forelse ($pages as $page)
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-2 flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <flux:text class="font-medium">{{ $page->label }}</flux:text>
                        <flux:text size="sm" class="truncate text-zinc-500 font-mono">{{ $page->route_name }}</flux:text>
                    </div>
                    <flux:switch
                        :checked="$page->is_active"
                        wire:click="toggleActive({{ $page->id }})"
                        wire:loading.attr="disabled"
                    />
                </div>
                <div class="flex items-center gap-2">
                    <flux:button size="sm" icon="pencil" wire:click="edit({{ $page->id }})">Edit</flux:button>
                    <flux:button size="sm" icon="trash" variant="danger" wire:click="delete({{ $page->id }})" wire:confirm="Remove &quot;{{ $page->label }}&quot; from trackable pages and clear it from all teachers' Fast Pass histories?">Delete</flux:button>
                </div>
            </div>
        @empty
            <flux:text class="text-zinc-500">No trackable pages defined yet.</flux:text>
        @endforelse
    </div>

    {{-- Desktop: full table --}}
    <div class="hidden md:block">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Label</flux:table.column>
                <flux:table.column>Route Name</flux:table.column>
                <flux:table.column>Active</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($pages as $page)
                    <flux:table.row :key="$page->id">
                        <flux:table.cell class="font-medium">{{ $page->label }}</flux:table.cell>
                        <flux:table.cell class="font-mono text-sm text-zinc-500">{{ $page->route_name }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:switch
                                :checked="$page->is_active"
                                wire:click="toggleActive({{ $page->id }})"
                                wire:loading.attr="disabled"
                            />
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <flux:button size="sm" icon="pencil" wire:click="edit({{ $page->id }})">Edit</flux:button>
                                <flux:button size="sm" icon="trash" variant="danger" wire:click="delete({{ $page->id }})" wire:confirm="Remove &quot;{{ $page->label }}&quot; from trackable pages and clear it from all teachers' Fast Pass histories?">Delete</flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="text-zinc-500">No trackable pages defined yet.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    {{-- Add / Edit modal --}}
    <flux:modal name="trackable-page-modal" class="w-full max-w-md">
        <div class="space-y-6">
            <flux:heading>{{ $isAdding ? 'Add Trackable Page' : 'Edit Trackable Page' }}</flux:heading>

            @if ($isAdding)
                <flux:field>
                    <flux:label>Route Name</flux:label>
                    <flux:select wire:model="edit_route_name" placeholder="Select a route...">
                        @foreach ($availableRoutes as $route)
                            <flux:select.option value="{{ $route }}">{{ $route }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="edit_route_name" />
                </flux:field>
            @else
                <flux:field>
                    <flux:label>Route Name</flux:label>
                    <flux:input value="{{ $edit_route_name }}" disabled />
                </flux:field>
            @endif

            <flux:field>
                <flux:label>Display Label</flux:label>
                <flux:input wire:model="edit_label" placeholder="e.g. Dashboard" autofocus />
                <flux:error name="edit_label" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                @if ($isAdding)
                    <flux:button variant="primary" wire:click="saveAdd">Add Page</flux:button>
                @else
                    <flux:button variant="primary" wire:click="saveEdit">Save Changes</flux:button>
                @endif
            </div>
        </div>
    </flux:modal>
</div>
