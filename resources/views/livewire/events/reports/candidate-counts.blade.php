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
            <flux:badge color="zinc" size="sm">{{ $voicePart->abbr }}: {{ $summary[$voicePart->id] }}</flux:badge>
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
                            <x-reports.teacher-contact-lines :teacher="$row['teacher']" />
                        </div>
                        <flux:badge color="blue" size="sm">Total: {{ $row['total'] }}</flux:badge>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2 text-sm text-zinc-500">
                        @foreach ($voiceParts as $voicePart)
                            <span>{{ $voicePart->abbr }}: {{ $row['countsByVoicePart'][$voicePart->id] }}</span>
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
                    @foreach ($voiceParts as $voicePart)
                        <flux:table.column>{{ $voicePart->abbr }}</flux:table.column>
                    @endforeach
                    <flux:table.column sortable :sorted="$sortColumn === 'total'" :direction="$sortDirection" wire:click="sortBy('total')">Total</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($rows as $row)
                        <flux:table.row>
                            <flux:table.cell class="font-medium">{{ $row['school']->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <div>{{ $row['teacher']->user->name }}</div>
                                <x-reports.teacher-contact-lines :teacher="$row['teacher']" class="mt-0.5" />
                            </flux:table.cell>
                            @foreach ($voiceParts as $voicePart)
                                <flux:table.cell>{{ $row['countsByVoicePart'][$voicePart->id] }}</flux:table.cell>
                            @endforeach
                            <flux:table.cell class="font-medium">{{ $row['total'] }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach

                    {{-- Shading/padding on inner divs, not the cells themselves —
                         Flux's first:ps-0/last:pe-0 cell rules zero the outer
                         edge padding on the first and last cell of a row. --}}
                    <flux:table.row :key="'totals'">
                        <flux:table.cell>
                            <div class="-my-3 -mr-3 bg-zinc-100 py-3 pr-3 pl-3 font-semibold dark:bg-zinc-700">Totals</div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="-m-3 bg-zinc-100 p-3 dark:bg-zinc-700"></div>
                        </flux:table.cell>
                        @foreach ($voiceParts as $voicePart)
                            <flux:table.cell>
                                <div class="-m-3 bg-zinc-100 p-3 font-semibold dark:bg-zinc-700">{{ $summary[$voicePart->id] }}</div>
                            </flux:table.cell>
                        @endforeach
                        <flux:table.cell>
                            <div class="-my-3 -ml-3 bg-zinc-100 py-3 pr-3 pl-3 font-semibold dark:bg-zinc-700">{{ array_sum($summary) }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</div>
