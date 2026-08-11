<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.tab-room.reports.index', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Reports</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Student Seniority</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">Student Seniority</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — an Ensemble's accepted members across every prior season</flux:text>
        </div>

        @if ($ensembleId !== null)
            <div class="flex gap-2">
                <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('events.versions.tab-room.reports.student-seniority.export', ['version' => $version, 'ensemble_id' => $ensembleId, 'format' => 'pdf'])" target="_blank">
                    Export PDF
                </flux:button>
                <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('events.versions.tab-room.reports.student-seniority.export', ['version' => $version, 'ensemble_id' => $ensembleId, 'format' => 'csv'])" target="_blank">
                    Export CSV
                </flux:button>
            </div>
        @endif
    </div>

    <div class="mb-4 max-w-xs">
        <flux:select wire:model.live="ensembleId" placeholder="Choose an Ensemble...">
            @foreach ($ensembles as $ensemble)
                <flux:select.option value="{{ $ensemble->id }}">{{ $ensemble->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($ensembleId === null)
        <flux:callout variant="info" icon="information-circle">
            <flux:callout.text>Choose an Ensemble to see its members' seniority.</flux:callout.text>
        </flux:callout>
    @elseif ($rows->isEmpty())
        <flux:callout variant="info" icon="information-circle">
            <flux:callout.text>No accepted candidates for this Ensemble yet.</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Student</flux:table.column>
                    <flux:table.column>School</flux:table.column>
                    <flux:table.column>Teacher</flux:table.column>
                    <flux:table.column align="center">Voice Part</flux:table.column>
                    @foreach ($years as $year)
                        <flux:table.column align="center">{{ $year }}</flux:table.column>
                    @endforeach
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($rows as $row)
                        <flux:table.row :key="$row['candidate']->id">
                            <flux:table.cell class="font-medium">{{ $row['candidate']->student->user->sort_name }}</flux:table.cell>
                            <flux:table.cell>{{ $row['candidate']->school->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $row['candidate']->teacher->user->name }}</flux:table.cell>
                            <flux:table.cell align="center">{{ $row['candidate']->voicePart?->abbr ?? '—' }}</flux:table.cell>
                            @foreach ($years as $year)
                                <flux:table.cell align="center">
                                    @if ($row['years'][$year])
                                        <flux:badge size="sm" color="green">Y</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="red">N</flux:badge>
                                    @endif
                                </flux:table.cell>
                            @endforeach
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</div>
