<div x-on:pair-preselected.window="window.scrollTo({top: 0, behavior: 'smooth'})">
    <div class="mb-6">
        <flux:heading size="xl">Merge Students</flux:heading>
        <flux:subheading>Find two records for the same real student, pick which one to keep, and permanently merge them.</flux:subheading>
    </div>

    @if ($winnerId && $loserId && $winnerId === $loserId)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-4">
            <flux:callout.text>Winner and loser must be different students.</flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">

        {{-- ================================================================
             Winner column — the record to KEEP
             ================================================================ --}}
        <div>
            <div class="mb-3 flex items-center gap-2">
                <flux:badge color="green">KEEP</flux:badge>
                <flux:heading size="lg">Winner</flux:heading>
            </div>

            @if ($this->winner)
                <x-founder.student-merge-card :student="$this->winner" clear-method="clearWinner" color="green" />
            @else
                <flux:input
                    wire:model.live.debounce.300ms="winnerSearch"
                    placeholder="Search by first or last name…"
                    icon="magnifying-glass"
                    autofocus
                />

                @if (mb_strlen(trim($winnerSearch)) >= 2 && $winnerResults->isEmpty())
                    <flux:text class="mt-2 text-zinc-500">No students match "{{ $winnerSearch }}".</flux:text>
                @endif

                <div class="mt-2 flex flex-col gap-2">
                    @foreach ($winnerResults as $student)
                        <div class="flex items-center justify-between gap-3 rounded-md border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="min-w-0">
                                <flux:text class="font-medium">{{ $student->user->first_name }} {{ $student->user->last_name }}</flux:text>
                                <flux:text size="sm" class="truncate text-zinc-500">
                                    {{ $student->user->email }}
                                    @if ($student->schools->isNotEmpty())
                                        &middot; {{ $student->schools->pluck('name')->join(', ') }}
                                    @endif
                                </flux:text>
                            </div>
                            <flux:button size="sm" wire:click="selectWinner({{ $student->id }})">Select</flux:button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ================================================================
             Loser column — the record to DELETE
             ================================================================ --}}
        <div>
            <div class="mb-3 flex items-center gap-2">
                <flux:badge color="red">DELETE</flux:badge>
                <flux:heading size="lg">Loser</flux:heading>
            </div>

            @if ($this->loser)
                <x-founder.student-merge-card :student="$this->loser" clear-method="clearLoser" color="red" />
            @else
                <flux:input
                    wire:model.live.debounce.300ms="loserSearch"
                    placeholder="Search by first or last name…"
                    icon="magnifying-glass"
                />

                @if (mb_strlen(trim($loserSearch)) >= 2 && $loserResults->isEmpty())
                    <flux:text class="mt-2 text-zinc-500">No students match "{{ $loserSearch }}".</flux:text>
                @endif

                <div class="mt-2 flex flex-col gap-2">
                    @foreach ($loserResults as $student)
                        <div class="flex items-center justify-between gap-3 rounded-md border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="min-w-0">
                                <flux:text class="font-medium">{{ $student->user->first_name }} {{ $student->user->last_name }}</flux:text>
                                <flux:text size="sm" class="truncate text-zinc-500">
                                    {{ $student->user->email }}
                                    @if ($student->schools->isNotEmpty())
                                        &middot; {{ $student->schools->pluck('name')->join(', ') }}
                                    @endif
                                </flux:text>
                            </div>
                            <flux:button size="sm" wire:click="selectLoser({{ $student->id }})">Select</flux:button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

    </div>

    {{-- Merge trigger — only shown when both panels have a selection --}}
    @if ($this->winner && $this->loser && $winnerId !== $loserId)
        <div class="mt-8 flex items-center gap-4">
            <flux:button variant="danger" icon="arrow-path" wire:click="confirmMerge">
                Merge Students
            </flux:button>
            <flux:text size="sm" class="text-zinc-500">
                {{ $this->loser->user->first_name }} {{ $this->loser->user->last_name }} will be permanently deleted.
            </flux:text>
        </div>
    @endif

    {{-- ====================================================================
         Potential duplicates list
         ==================================================================== --}}
    <flux:separator class="my-8" />

    <div class="mb-4 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <flux:heading size="lg">Potential Duplicates</flux:heading>
            @if (count($this->potentialDuplicates) > 0)
                <flux:badge>{{ count($this->potentialDuplicates) }}</flux:badge>
            @endif
        </div>
        <flux:button size="sm" icon="arrow-path" wire:click="refreshDuplicates" wire:loading.attr="disabled">
            Refresh
        </flux:button>
    </div>

    @if (empty($this->potentialDuplicates))
        <flux:text class="text-zinc-500">No potential duplicates found.</flux:text>
    @else

        {{-- Mobile: card list --}}
        <div class="flex flex-col gap-3 md:hidden">
            @foreach ($this->potentialDuplicates as $pair)
                <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <flux:badge color="{{ $pair['strength'] === 'strong' ? 'green' : 'amber' }}" size="sm">
                                {{ $pair['strength'] === 'strong' ? 'Strong' : 'Weak' }}
                            </flux:badge>
                            <flux:text class="font-medium">{{ $pair['first_name'] }} {{ $pair['last_name'] }}</flux:text>
                        </div>
                        <flux:button size="sm" wire:click="preselectPair({{ $pair['winner_id'] }}, {{ $pair['loser_id'] }})">
                            Pre-fill
                        </flux:button>
                    </div>
                    <div class="grid grid-cols-2 gap-x-4 gap-y-1">
                        <div>
                            <flux:text size="sm" class="font-medium text-zinc-400 uppercase tracking-wide" style="font-size:0.65rem">Keep #{{ $pair['winner_id'] }}</flux:text>
                            <flux:text size="sm" class="break-all">{{ $pair['winner_email'] }}</flux:text>
                            <flux:text size="sm" class="text-zinc-500">{{ $pair['winner_schools'] }}</flux:text>
                        </div>
                        <div>
                            <flux:text size="sm" class="font-medium text-zinc-400 uppercase tracking-wide" style="font-size:0.65rem">Delete #{{ $pair['loser_id'] }}</flux:text>
                            <flux:text size="sm" class="break-all">{{ $pair['loser_email'] }}</flux:text>
                            <flux:text size="sm" class="text-zinc-500">{{ $pair['loser_schools'] }}</flux:text>
                        </div>
                    </div>
                    @if ($pair['strength'] === 'weak')
                        <flux:text size="sm" class="mt-2 text-amber-600 dark:text-amber-400">
                            Birthdays differ: {{ $pair['winner_birthday'] ?? '—' }} vs {{ $pair['loser_birthday'] ?? '—' }}
                        </flux:text>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Desktop: full table --}}
        <div class="hidden md:block">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Match</flux:table.column>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Keep (winner)</flux:table.column>
                    <flux:table.column>Delete (loser)</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->potentialDuplicates as $pair)
                        <flux:table.row :key="$pair['winner_id'].'-'.$pair['loser_id']">
                            <flux:table.cell>
                                <flux:badge color="{{ $pair['strength'] === 'strong' ? 'green' : 'amber' }}" size="sm">
                                    {{ $pair['strength'] === 'strong' ? 'Strong' : 'Weak' }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="font-medium whitespace-nowrap">
                                {{ $pair['first_name'] }} {{ $pair['last_name'] }}
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:text size="sm" class="text-zinc-400">#{{ $pair['winner_id'] }}</flux:text>
                                <flux:text size="sm">{{ $pair['winner_email'] }}</flux:text>
                                <flux:text size="sm" class="text-zinc-500">{{ $pair['winner_schools'] }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:text size="sm" class="text-zinc-400">#{{ $pair['loser_id'] }}</flux:text>
                                <flux:text size="sm">{{ $pair['loser_email'] }}</flux:text>
                                <flux:text size="sm" class="text-zinc-500">{{ $pair['loser_schools'] }}</flux:text>
                                @if ($pair['strength'] === 'weak')
                                    <flux:text size="sm" class="text-amber-600 dark:text-amber-400">
                                        Birthday: {{ $pair['loser_birthday'] ?? '—' }} vs {{ $pair['winner_birthday'] ?? '—' }}
                                    </flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:button size="sm" wire:click="preselectPair({{ $pair['winner_id'] }}, {{ $pair['loser_id'] }})">
                                    Pre-fill
                                </flux:button>
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
    <flux:modal name="merge-confirm" class="w-full max-w-md">
        <div class="space-y-5">
            <flux:heading>Confirm merge</flux:heading>

            <div class="rounded-md border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900 space-y-2">
                <div class="flex items-start gap-2">
                    <flux:badge color="green" size="sm" class="mt-0.5 shrink-0">KEEP</flux:badge>
                    <div>
                        <flux:text class="font-medium">{{ $this->winner?->user->first_name }} {{ $this->winner?->user->last_name }}</flux:text>
                        <flux:text size="sm" class="text-zinc-500">ID {{ $this->winner?->id }} &middot; {{ $this->winner?->user->email }}</flux:text>
                    </div>
                </div>
                <div class="flex items-start gap-2">
                    <flux:badge color="red" size="sm" class="mt-0.5 shrink-0">DELETE</flux:badge>
                    <div>
                        <flux:text class="font-medium">{{ $this->loser?->user->first_name }} {{ $this->loser?->user->last_name }}</flux:text>
                        <flux:text size="sm" class="text-zinc-500">ID {{ $this->loser?->id }} &middot; {{ $this->loser?->user->email }}</flux:text>
                    </div>
                </div>
            </div>

            <flux:text size="sm" class="text-zinc-500">
                The loser's school enrollments, teacher connections, emergency contacts, and home address will be transferred to the winner. The loser's student record and user account will be <strong>permanently deleted</strong>.
            </flux:text>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="merge">
                    Confirm merge
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
