<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.tab-room.reports.index', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Reports</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Ensemble Participation</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">Ensemble Participation</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — accepted members of one Ensemble</flux:text>
        </div>

        @if ($ensembleId !== null)
            <div class="flex gap-2">
                <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('events.versions.tab-room.reports.ensemble-participation.export', ['version' => $version, 'ensemble_id' => $ensembleId, 'format' => 'pdf'])" target="_blank">
                    Export PDF
                </flux:button>
                <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('events.versions.tab-room.reports.ensemble-participation.export', ['version' => $version, 'ensemble_id' => $ensembleId, 'format' => 'csv'])" target="_blank">
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
            <flux:callout.text>Choose an Ensemble to see its accepted members.</flux:callout.text>
        </flux:callout>
    @elseif ($rows->isEmpty())
        <flux:callout variant="info" icon="information-circle">
            <flux:callout.text>No accepted candidates for this Ensemble yet.</flux:callout.text>
        </flux:callout>
    @else
        {{-- Cards below lg:, table at lg:+ --}}
        <div class="lg:hidden space-y-3">
            @foreach ($rows as $row)
                <flux:card size="sm">
                    <div class="flex items-center gap-3">
                        <flux:heading size="base">{{ $row['candidate']->student->user->sort_name }}</flux:heading>
                        <flux:spacer />
                        <flux:badge size="sm">{{ $row['candidate']->voicePart?->abbr ?? '—' }}</flux:badge>
                    </div>
                    <div class="mt-2 space-y-1 text-sm text-zinc-500">
                        <div>{{ $row['candidate']->school->name ?? '—' }} — Grade {{ $row['candidate']->student->grade ?? '—' }}</div>
                        <div>{{ $row['candidate']->teacher->user->name }} ({{ $row['candidate']->teacher->user->email }})</div>
                        <div>Emergency: {{ $row['candidate']->emergencyContact->name ?? '—' }} {{ $row['candidate']->emergencyContact->preferred_phone ?? '' }}</div>
                        <div>Total: {{ $row['total'] }}</div>
                    </div>
                </flux:card>
            @endforeach
        </div>

        <div class="hidden lg:block">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Student</flux:table.column>
                    <flux:table.column align="center">Grade</flux:table.column>
                    <flux:table.column align="center">Voice Part</flux:table.column>
                    <flux:table.column>School</flux:table.column>
                    <flux:table.column>Teacher</flux:table.column>
                    <flux:table.column>Emergency Contact</flux:table.column>
                    <flux:table.column align="center">Total</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($rows as $row)
                        <flux:table.row :key="$row['candidate']->id">
                            <flux:table.cell class="font-medium">{{ $row['candidate']->student->user->sort_name }}</flux:table.cell>
                            <flux:table.cell align="center">{{ $row['candidate']->student->grade ?? '—' }}</flux:table.cell>
                            <flux:table.cell align="center">{{ $row['candidate']->voicePart?->abbr ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $row['candidate']->school->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $row['candidate']->teacher->user->name }}
                                <flux:text size="sm" class="text-zinc-500 block">{{ $row['candidate']->teacher->user->email }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $row['candidate']->emergencyContact->name ?? '—' }}
                                @if ($row['candidate']->emergencyContact?->preferred_phone)
                                    <flux:text size="sm" class="text-zinc-500 block">{{ $row['candidate']->emergencyContact->preferred_phone }}</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="center" class="tabular-nums">{{ $row['total'] }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</div>
