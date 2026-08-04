<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.reports', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Reports</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Participation by County</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">Participation by County</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — counts by county</flux:text>
        </div>

        <div class="flex gap-2">
            <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('events.versions.reports.participation-by-county.export', ['version' => $version, 'format' => 'pdf'])" target="_blank">
                PDF
            </flux:button>
            <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('events.versions.reports.participation-by-county.export', ['version' => $version, 'format' => 'csv'])">
                CSV
            </flux:button>
        </div>
    </div>

    @if ($rows->isEmpty())
        <flux:callout variant="info" icon="magnifying-glass">
            <flux:callout.text>No counties are in scope for this Version.</flux:callout.text>
        </flux:callout>
    @else
        {{-- Cards below lg:, table at lg:+ --}}
        <div class="lg:hidden space-y-3">
            @foreach ($rows as $row)
                <flux:card size="sm">
                    <flux:heading size="base">{{ $row['county']->name }}</flux:heading>
                    <div class="mt-2 space-y-1 text-sm">
                        <div>Obligated teachers: {{ $row['obligatedTeacherCount'] }}</div>
                        <div>Participating teachers: {{ $row['participatingTeacherCount'] }}</div>
                        <div>Candidates: {{ $row['candidateCount'] }}</div>
                        <div class="text-zinc-500">Manager: {{ $row['managerName'] ?? '—' }}</div>
                    </div>
                </flux:card>
            @endforeach
        </div>

        <div class="hidden lg:block">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>County</flux:table.column>
                    <flux:table.column>Obligated Teachers</flux:table.column>
                    <flux:table.column>Participating Teachers</flux:table.column>
                    <flux:table.column>Candidates</flux:table.column>
                    <flux:table.column>Manager</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($rows as $row)
                        <flux:table.row :key="$row['county']->id">
                            <flux:table.cell class="font-medium">{{ $row['county']->name }}</flux:table.cell>
                            <flux:table.cell>{{ $row['obligatedTeacherCount'] }}</flux:table.cell>
                            <flux:table.cell>{{ $row['participatingTeacherCount'] }}</flux:table.cell>
                            <flux:table.cell>{{ $row['candidateCount'] }}</flux:table.cell>
                            <flux:table.cell>{{ $row['managerName'] ?? '—' }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</div>
