<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>{{ $version->name }}</span>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Invitations</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">Invitations</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — who may participate in this Version</flux:text>
        </div>

        <div class="flex gap-2">
            <flux:button size="sm" wire:click="inviteAll" wire:confirm="Invite every eligible teacher not yet invited?">
                Invite All
            </flux:button>
            <flux:button size="sm" variant="ghost" wire:click="removeAll" wire:confirm="Remove every invitation that hasn't been acted on yet?">
                Remove All
            </flux:button>
        </div>
    </div>

    @if ($hasEligibleTeachers)
        {{-- Status summary — click a tile to filter, click again to clear --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
            @foreach ([
                'eligible' => ['label' => 'Eligible', 'dot' => 'bg-zinc-400'],
                'invited' => ['label' => 'Invited', 'dot' => 'bg-blue-500'],
                'obligated' => ['label' => 'Obligated', 'dot' => 'bg-amber-500'],
                'participating' => ['label' => 'Participating', 'dot' => 'bg-green-500'],
            ] as $statusKey => $tile)
                <button
                    type="button"
                    wire:click="filterByStatus('{{ $statusKey }}')"
                    class="text-left rounded-lg border px-3 py-2 transition-colors cursor-pointer
                        {{ $statusFilter === $statusKey
                            ? 'border-zinc-800 dark:border-white bg-zinc-50 dark:bg-zinc-800'
                            : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600' }}"
                >
                    <div class="flex items-center gap-1.5 mb-1">
                        <span class="w-2 h-2 rounded-full {{ $tile['dot'] }}"></span>
                        <flux:text size="sm" class="text-zinc-500">{{ $tile['label'] }}</flux:text>
                    </div>
                    <flux:heading size="lg">{{ $statusCounts[$statusKey] }}</flux:heading>
                </button>
            @endforeach
        </div>

        <div class="flex flex-col sm:flex-row gap-3 mb-4">
            <flux:input
                wire:model.live.debounce.300ms="search"
                placeholder="Search by name, email, or school..."
                icon="magnifying-glass"
                class="sm:max-w-xs"
            />
            <flux:select wire:model.live="statusFilter" placeholder="All statuses" class="sm:max-w-2xs">
                <flux:select.option value="">All statuses</flux:select.option>
                <flux:select.option value="eligible">Eligible</flux:select.option>
                <flux:select.option value="invited">Invited</flux:select.option>
                <flux:select.option value="obligated">Obligated</flux:select.option>
                <flux:select.option value="participating">Participating</flux:select.option>
            </flux:select>
        </div>
    @endif

    @if (! $hasEligibleTeachers)
        <flux:callout variant="info" icon="information-circle">
            <flux:callout.text>
                No teachers are currently eligible for this Version. Eligibility is based on an active, verified school in one of this Version's configured counties, or organization membership.
            </flux:callout.text>
        </flux:callout>
    @elseif ($roster->isEmpty())
        <flux:callout variant="info" icon="magnifying-glass">
            <flux:callout.text>No teachers match your search or filter.</flux:callout.text>
        </flux:callout>
    @else
        {{-- Cards below md:, table at md:+ --}}
        <div class="md:hidden space-y-3">
            @foreach ($roster as $row)
                <flux:card size="sm">
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <div class="min-w-0">
                            <flux:heading size="base" class="truncate">{{ $row->teacher->user->name }}</flux:heading>
                            <flux:text size="sm" class="text-zinc-500 truncate">{{ $row->teacher->user->email }}</flux:text>
                        </div>
                        <label class="flex items-center shrink-0">
                            <flux:checkbox
                                wire:click="toggle({{ $row->teacher->id }})"
                                :checked="$row->invitation !== null"
                            />
                        </label>
                    </div>

                    <flux:text size="sm" class="text-zinc-500 mb-1">
                        {{ $row->school?->name ?? '—' }}
                        @if ($row->school?->county)
                            ({{ $row->school->county->name }})
                        @endif
                    </flux:text>

                    <div class="flex items-center justify-between mt-2">
                        <flux:text size="sm" class="text-zinc-500">
                            Membership expires: {{ $row->membershipExpiresAt?->format('M j, Y') ?? '—' }}
                        </flux:text>

                        @if ($row->status === 'eligible')
                            <flux:badge color="zinc" size="sm">Eligible</flux:badge>
                        @elseif ($row->status === 'invited')
                            <flux:badge color="blue" size="sm">Invited</flux:badge>
                        @elseif ($row->status === 'obligated')
                            <flux:badge color="amber" size="sm">Obligated</flux:badge>
                        @else
                            <flux:badge color="green" size="sm">Participating</flux:badge>
                        @endif
                    </div>
                </flux:card>
            @endforeach
        </div>

        <flux:table class="hidden md:table">
            <flux:table.columns>
                <flux:table.column></flux:table.column>
                <flux:table.column sortable :sorted="$sortColumn === 'teacher'" :direction="$sortDirection" wire:click="sortBy('teacher')">
                    Teacher
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortColumn === 'email'" :direction="$sortDirection" wire:click="sortBy('email')">
                    Email
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortColumn === 'school'" :direction="$sortDirection" wire:click="sortBy('school')">
                    School (County)
                </flux:table.column>
                <flux:table.column>Membership Expires</flux:table.column>
                <flux:table.column>Status</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($roster as $row)
                    <flux:table.row>
                        <flux:table.cell>
                            <flux:checkbox
                                wire:click="toggle({{ $row->teacher->id }})"
                                :checked="$row->invitation !== null"
                            />
                        </flux:table.cell>
                        <flux:table.cell class="font-medium">{{ $row->teacher->user->name }}</flux:table.cell>
                        <flux:table.cell class="text-zinc-500">{{ $row->teacher->user->email }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $row->school?->name ?? '—' }}
                            @if ($row->school?->county)
                                <span class="text-zinc-500">({{ $row->school->county->name }})</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-zinc-500">
                            {{ $row->membershipExpiresAt?->format('M j, Y') ?? '—' }}
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($row->status === 'eligible')
                                <flux:badge color="zinc" size="sm">Eligible</flux:badge>
                            @elseif ($row->status === 'invited')
                                <flux:badge color="blue" size="sm">Invited</flux:badge>
                            @elseif ($row->status === 'obligated')
                                <flux:badge color="amber" size="sm">Obligated</flux:badge>
                            @else
                                <flux:badge color="green" size="sm">Participating</flux:badge>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
