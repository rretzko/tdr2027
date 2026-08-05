<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.reports', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Reports</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Participating Teachers</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">Participating Teachers</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — teachers with registered candidates</flux:text>
        </div>

        <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('events.versions.reports.participating-teachers.pdf', $version)" target="_blank">
            Export PDF
        </flux:button>
    </div>

    <div class="mb-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by first name, last name, or school..." icon="magnifying-glass" class="sm:max-w-sm" />
    </div>

    @if ($rows->isEmpty())
        <flux:callout variant="info" icon="magnifying-glass">
            <flux:callout.text>
                @if ($search !== '')
                    No participating teachers match your search.
                @else
                    No teachers have registered candidates yet.
                @endif
            </flux:callout.text>
        </flux:callout>
    @else
        {{-- Cards below lg:, table at lg:+ --}}
        <div class="lg:hidden space-y-3">
            @foreach ($rows as $row)
                <flux:card size="sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <flux:heading size="base">{{ $row['teacher']->user->name }}</flux:heading>
                            <flux:text size="sm" class="text-zinc-500">{{ $row['school']->name ?? '—' }}</flux:text>
                        </div>
                        <flux:badge color="zinc" size="sm">{{ $row['count'] }} registered</flux:badge>
                    </div>
                    <div class="mt-2 space-y-1">
                        <x-reports.teacher-contact-lines :teacher="$row['teacher']" />
                    </div>
                </flux:card>
            @endforeach
        </div>

        <div class="hidden lg:block">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortColumn === 'last_name'" :direction="$sortDirection" wire:click="sortBy('last_name')">Teacher</flux:table.column>
                    <flux:table.column sortable :sorted="$sortColumn === 'school'" :direction="$sortDirection" wire:click="sortBy('school')">School</flux:table.column>
                    <flux:table.column sortable :sorted="$sortColumn === 'count'" :direction="$sortDirection" wire:click="sortBy('count')">Registered</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($rows as $row)
                        <flux:table.row :key="$row['teacher']->id">
                            <flux:table.cell>
                                <div class="font-medium">{{ $row['teacher']->user->name }}</div>
                                <x-reports.teacher-contact-lines :teacher="$row['teacher']" class="mt-0.5" />
                            </flux:table.cell>
                            <flux:table.cell>{{ $row['school']->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $row['count'] }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</div>
