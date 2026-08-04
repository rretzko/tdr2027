<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.reports', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Reports</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Candidate Counts</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">Candidate Counts</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — counts by school, teacher, and voice part</flux:text>
        </div>

        <div class="flex gap-2">
            <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('events.versions.reports.candidate-counts.export', ['version' => $version, 'format' => 'pdf', 'search' => $search])" target="_blank">
                PDF
            </flux:button>
            <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('events.versions.reports.candidate-counts.export', ['version' => $version, 'format' => 'csv', 'search' => $search])">
                CSV
            </flux:button>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach ($voiceParts as $voicePart)
            <flux:badge color="zinc" size="sm">{{ $voicePart->name }}: {{ $summary[$voicePart->id] }}</flux:badge>
        @endforeach
        <flux:badge color="blue" size="sm">Total: {{ array_sum($summary) }}</flux:badge>
    </div>

    <div class="mb-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by school or teacher..." icon="magnifying-glass" class="sm:max-w-sm" />
    </div>

    @if ($rows->isEmpty())
        <flux:callout variant="info" icon="magnifying-glass">
            <flux:callout.text>
                @if ($search !== '')
                    No rows match your search.
                @else
                    No candidates are registered for this Version yet.
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
                            <flux:heading size="base">{{ $row['school']->name ?? '—' }}</flux:heading>
                            <flux:text size="sm" class="text-zinc-500">{{ $row['teacher']->user->name }}</flux:text>
                        </div>
                        <flux:badge color="blue" size="sm">Total: {{ $row['total'] }}</flux:badge>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2 text-sm text-zinc-500">
                        @foreach ($voiceParts as $voicePart)
                            <span>{{ $voicePart->name }}: {{ $row['countsByVoicePart'][$voicePart->id] }}</span>
                        @endforeach
                    </div>
                </flux:card>
            @endforeach
        </div>

        <div class="hidden lg:block">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortColumn === 'school'" :direction="$sortDirection" wire:click="sortBy('school')">School</flux:table.column>
                    <flux:table.column sortable :sorted="$sortColumn === 'teacher'" :direction="$sortDirection" wire:click="sortBy('teacher')">Teacher</flux:table.column>
                    <flux:table.column>Phone(s)</flux:table.column>
                    @foreach ($voiceParts as $voicePart)
                        <flux:table.column>{{ $voicePart->name }}</flux:table.column>
                    @endforeach
                    <flux:table.column sortable :sorted="$sortColumn === 'total'" :direction="$sortDirection" wire:click="sortBy('total')">Total</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($rows as $row)
                        <flux:table.row>
                            <flux:table.cell class="font-medium">{{ $row['school']->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $row['teacher']->user->name }}</flux:table.cell>
                            <flux:table.cell>{{ $row['phones'] }}</flux:table.cell>
                            @foreach ($voiceParts as $voicePart)
                                <flux:table.cell>{{ $row['countsByVoicePart'][$voicePart->id] }}</flux:table.cell>
                            @endforeach
                            <flux:table.cell class="font-medium">{{ $row['total'] }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</div>
