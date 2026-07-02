<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.index') }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Events</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>{{ $event->name }}</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">{{ $event->name }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $event->organization->name }}</flux:text>
        </div>

        <flux:modal.trigger name="add-version">
            <flux:button variant="primary" icon="plus" wire:click="openAddVersion">
                Add version
            </flux:button>
        </flux:modal.trigger>
    </div>

    {{-- Event summary badges --}}
    <div class="flex flex-wrap gap-2 mb-6">
        @php $rawStatus = $event->getRawOriginal('status'); @endphp
        @if ($rawStatus === 'active')
            <flux:badge color="green">Active</flux:badge>
        @elseif ($rawStatus === 'sandbox')
            <flux:badge color="amber">Sandbox</flux:badge>
        @elseif ($rawStatus === 'inactive')
            <flux:badge color="zinc">Inactive</flux:badge>
        @else
            <flux:badge color="red">Closed</flux:badge>
        @endif

        <flux:badge color="blue" class="capitalize">{{ $event->getRawOriginal('frequency') }}</flux:badge>
        <flux:badge color="zinc">{{ $event->audition_count }} {{ Str::plural('audition', $event->audition_count) }}</flux:badge>
        <flux:badge color="zinc">{{ $event->ensemble_count }} {{ Str::plural('ensemble', $event->ensemble_count) }}</flux:badge>
    </div>

    {{-- Versions — cards below md:, table at md:+ --}}
    <div class="md:hidden space-y-3">
        @forelse ($versions as $version)
            <flux:card size="sm">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <flux:heading size="base" class="truncate">{{ $version->name }}</flux:heading>
                        <flux:text size="sm" class="text-zinc-500">Class of {{ $version->senior_class_of }}</flux:text>
                    </div>

                    <div class="flex flex-col items-end gap-2 shrink-0">
                        @php $vs = $version->getRawOriginal('status'); @endphp
                        @if ($vs === 'active')
                            <flux:badge color="green" size="sm">Active</flux:badge>
                        @elseif ($vs === 'sandbox')
                            <flux:badge color="amber" size="sm">Sandbox</flux:badge>
                        @elseif ($vs === 'inactive')
                            <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                        @else
                            <flux:badge color="red" size="sm">Closed</flux:badge>
                        @endif

                        <flux:button size="sm" :href="route('events.versions.edit', $version)" wire:navigate>
                            Configure
                        </flux:button>
                    </div>
                </div>
            </flux:card>
        @empty
            <flux:text class="text-zinc-500 py-4 text-center">No versions yet. Add one above.</flux:text>
        @endforelse
    </div>

    <flux:table class="hidden md:table">
        <flux:table.columns>
            <flux:table.column>Version</flux:table.column>
            <flux:table.column>Class Of</flux:table.column>
            <flux:table.column>Type</flux:table.column>
            <flux:table.column>Upload</flux:table.column>
            <flux:table.column>Status</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($versions as $version)
                <flux:table.row>
                    <flux:table.cell class="font-medium">{{ $version->name }}</flux:table.cell>
                    <flux:table.cell>{{ $version->senior_class_of }}</flux:table.cell>
                    <flux:table.cell class="capitalize">{{ $version->getRawOriginal('audition_type') }}</flux:table.cell>
                    <flux:table.cell class="capitalize">{{ $version->getRawOriginal('upload_type') }}</flux:table.cell>
                    <flux:table.cell>
                        @php $vs = $version->getRawOriginal('status'); @endphp
                        @if ($vs === 'active')
                            <flux:badge color="green" size="sm">Active</flux:badge>
                        @elseif ($vs === 'sandbox')
                            <flux:badge color="amber" size="sm">Sandbox</flux:badge>
                        @elseif ($vs === 'inactive')
                            <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                        @else
                            <flux:badge color="red" size="sm">Closed</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex justify-end">
                            <flux:button size="sm" :href="route('events.versions.edit', $version)" wire:navigate>
                                Configure
                            </flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center text-zinc-500 py-6">
                        No versions yet. Add one above.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="add-version" class="w-full max-w-md">
        <flux:heading size="lg" class="mb-4">Add Version</flux:heading>

        <div class="space-y-4">
            <flux:field>
                <flux:label>Version Name</flux:label>
                <flux:input wire:model="new_name" placeholder="e.g. 2025 All-State Chorus" />
                <flux:error name="new_name" />
            </flux:field>

            <flux:field>
                <flux:label>Short Name</flux:label>
                <flux:input wire:model="new_short_name" placeholder="e.g. 2025 All-State" />
                <flux:error name="new_short_name" />
            </flux:field>

            <flux:field>
                <flux:label>Senior Class Of</flux:label>
                <flux:input wire:model="new_senior_class_of" type="number" min="2000" max="2100" />
                <flux:description>The graduating year of seniors eligible for this version.</flux:description>
                <flux:error name="new_senior_class_of" />
            </flux:field>
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
            <flux:button variant="primary" wire:click="createVersion">
                Create Version
            </flux:button>
        </div>
    </flux:modal>
</div>
