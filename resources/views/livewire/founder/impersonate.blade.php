<div>
    <div class="mb-6">
        <flux:heading size="xl">Impersonate User</flux:heading>
        <flux:subheading>Search for a teacher by first or last name, then log in as them.</flux:subheading>
    </div>

    <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by first or last name..." icon="magnifying-glass" class="mb-4 sm:max-w-xs" autofocus />

    @if (trim($search) === '')
        <flux:text class="text-zinc-500">Start typing a name to see matches.</flux:text>
    @elseif ($teachers->isEmpty())
        <flux:text class="text-zinc-500">No teachers match "{{ $search }}".</flux:text>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($teachers as $user)
                <div class="flex items-center justify-between gap-3 rounded-md border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="min-w-0">
                        <flux:text class="font-medium">{{ $user->first_name }} {{ $user->last_name }}</flux:text>
                        <flux:text size="sm" class="text-zinc-500">
                            {{ $user->email }}
                            @if ($user->teacher->schools->isNotEmpty())
                                &middot; {{ $user->teacher->schools->pluck('name')->join(', ') }}
                            @endif
                        </flux:text>
                    </div>
                    <flux:button size="sm" variant="primary" wire:click="impersonate({{ $user->id }})">
                        Impersonate
                    </flux:button>
                </div>
            @endforeach
        </div>
    @endif
</div>
