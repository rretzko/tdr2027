<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">Remove Bot-registrations</flux:heading>
            <flux:subheading>
                Accounts that never verified their email, never logged in, never visited a page, and never joined a school &mdash; the pattern of a registration bot from before the honeypot guard was added.
            </flux:subheading>
        </div>
        <flux:button size="sm" icon="arrow-path" wire:click="refresh" wire:loading.attr="disabled">
            Refresh
        </flux:button>
    </div>

    @if (empty($this->suspects))
        <flux:text class="text-zinc-500">No suspected bot registrations found.</flux:text>
    @else
        <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <flux:heading size="lg">Suspected Bot Registrations</flux:heading>
                <flux:badge>{{ count($this->suspects) }}</flux:badge>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if (count($selected) < count($this->suspects))
                    <flux:button size="sm" variant="ghost" wire:click="selectAll">Select All</flux:button>
                @else
                    <flux:button size="sm" variant="ghost" wire:click="deselectAll">Deselect All</flux:button>
                @endif
                <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmRemoval" :disabled="empty($selected)">
                    Remove Selected ({{ count($selected) }})
                </flux:button>
            </div>
        </div>

        {{-- Mobile: card list --}}
        <div class="flex flex-col gap-3 md:hidden">
            @foreach ($this->suspects as $s)
                <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="mb-2 flex items-start gap-3">
                        <flux:checkbox wire:model="selected" value="{{ $s['user_id'] }}" />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <flux:text class="font-medium">{{ $s['name'] !== '' ? $s['name'] : '(no name)' }}</flux:text>
                                <flux:badge size="sm">{{ $s['type'] }}</flux:badge>
                            </div>
                            <flux:text size="sm" class="truncate text-zinc-500">{{ $s['email'] }}</flux:text>
                            <flux:text size="sm" class="text-zinc-500">Registered {{ $s['created_at'] }}</flux:text>
                        </div>
                    </div>
                    <ul class="ml-7 list-disc space-y-0.5">
                        @foreach ($s['reasons'] as $reason)
                            <li><flux:text size="sm" class="text-zinc-500">{{ $reason }}</flux:text></li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        {{-- Desktop: full table --}}
        <div class="hidden md:block">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column></flux:table.column>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Type</flux:table.column>
                    <flux:table.column>Email</flux:table.column>
                    <flux:table.column>Registered</flux:table.column>
                    <flux:table.column>Reasons</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->suspects as $s)
                        <flux:table.row :key="$s['user_id']">
                            <flux:table.cell>
                                <flux:checkbox wire:model="selected" value="{{ $s['user_id'] }}" />
                            </flux:table.cell>
                            <flux:table.cell class="font-medium whitespace-nowrap">
                                {{ $s['name'] !== '' ? $s['name'] : '(no name)' }}
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm">{{ $s['type'] }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="break-all">{{ $s['email'] }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap text-zinc-500">{{ $s['created_at'] }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:text size="sm" class="text-zinc-500">{{ implode(', ', $s['reasons']) }}</flux:text>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    {{-- ====================================================================
         Confirmation modal
         ==================================================================== --}}
    <flux:modal name="remove-confirm" class="w-full max-w-lg">
        <div class="space-y-5">
            <flux:heading>Confirm removal</flux:heading>

            <flux:text size="sm" class="text-zinc-500">
                The following {{ count($selected) }} account(s) and all their associated data will be
                <strong>permanently deleted</strong>. This cannot be undone.
            </flux:text>

            <div class="max-h-72 space-y-2 overflow-y-auto rounded-md border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900">
                @php
                    $toRemove = collect($this->suspects)->whereIn('user_id', $selected);
                @endphp
                @foreach ($toRemove as $s)
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <flux:text class="font-medium">{{ $s['name'] !== '' ? $s['name'] : '(no name)' }}</flux:text>
                            <flux:text size="sm" class="truncate text-zinc-500">{{ $s['email'] }}</flux:text>
                        </div>
                        <flux:badge size="sm" class="shrink-0">{{ $s['type'] }}</flux:badge>
                    </div>
                @endforeach
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="removeSelected" wire:loading.attr="disabled">
                    Confirm removal
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
