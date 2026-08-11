<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.tab-room.reports.index', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Reports</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>{{ $heading }}</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">{{ $heading }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — every Voice Part in one Ensemble (or all of them)</flux:text>
        </div>

        @if ($ensembleId !== '')
            <div class="flex gap-2">
                @if ($ensembleId === 'all')
                    {{-- DomPDF can't render this report inline at "All Ensembles" scale (confirmed 2026-08-11: exceeded a 120s/2GB limit) — generated in the background and emailed instead. See CombinedAuditionScores::requestAllEnsemblesPdf(). --}}
                    <flux:button size="sm" variant="outline" icon="envelope" wire:click="requestAllEnsemblesPdf">
                        Email me the PDF
                    </flux:button>
                @else
                    <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route($exportRouteName, ['version' => $version, 'ensemble_id' => $ensembleId, 'confidential' => $confidential ? 1 : 0, 'format' => 'pdf'])" target="_blank">
                        Export PDF
                    </flux:button>
                @endif
                <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route($exportRouteName, ['version' => $version, 'ensemble_id' => $ensembleId, 'confidential' => $confidential ? 1 : 0, 'format' => 'csv'])" target="_blank">
                    Export CSV
                </flux:button>
            </div>
        @endif
    </div>

    <div class="mb-4 max-w-xs">
        <flux:select wire:model.live="ensembleId" placeholder="Choose an Ensemble...">
            <flux:select.option value="all">All Ensembles</flux:select.option>
            @foreach ($ensembles as $ensemble)
                <flux:select.option value="{{ $ensemble->id }}">{{ $ensemble->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($ensembleId === '')
        <flux:callout variant="info" icon="information-circle">
            <flux:callout.text>Choose an Ensemble (or "All Ensembles") to see its combined score tables.</flux:callout.text>
        </flux:callout>
    @elseif ($ensembleTables->isEmpty())
        <flux:callout variant="info" icon="information-circle">
            <flux:callout.text>This Ensemble has no Voice Parts configured.</flux:callout.text>
        </flux:callout>
    @else
        @php
            $headerCellClasses = 'py-3 px-3 first:ps-0 last:pe-0 text-sm font-medium text-zinc-800 dark:text-white border-b border-zinc-800/10 dark:border-white/20';
            // Two nested readability borders: every other Score Category
            // gets a heavier box around its whole column span, and every
            // judge_type section (always, not just every other) gets a
            // lighter box nested inside it — header rows through the last
            // data row. Where an edge is both a category and a judge
            // boundary, the heavier category border wins outright rather
            // than stacking two conflicting border-* utilities on one side.
            $boxLeft = 'border-l-2 border-l-zinc-400 dark:border-l-zinc-500';
            $boxRight = 'border-r-2 border-r-zinc-400 dark:border-r-zinc-500';
            $boxTop = 'border-t-2 border-t-zinc-400 dark:border-t-zinc-500';
            $boxBottom = 'border-b-2 border-b-zinc-400 dark:border-b-zinc-500';
            $judgeBoxLeft = 'border-l border-l-zinc-300 dark:border-l-zinc-600';
            $judgeBoxRight = 'border-r border-r-zinc-300 dark:border-r-zinc-600';
            $judgeBoxTop = 'border-t border-t-zinc-300 dark:border-t-zinc-600';
            $judgeBoxBottom = 'border-b border-b-zinc-300 dark:border-b-zinc-600';
            // Every even-numbered Score Category is shaded instead of a second
            // border style — a single explicit light/dark pair (never a bare
            // bg-* utility with no dark: partner). NOT bg-zinc-800 in dark
            // mode — the app shell's own <body> is already dark:bg-zinc-800
            // (components/layouts/app.blade.php), so that pair is invisible
            // here; zinc-100/zinc-700 is the pairing this Reports area's
            // other screens already use for a highlighted cell against that
            // same zinc-800 body (see candidate-counts.blade.php,
            // participation-by-county.blade.php).
            $shadeClass = 'bg-zinc-100 dark:bg-zinc-700';
            // Row hover (a `group` on the <tr>, `group-hover:bg-*` on every
            // cell) helps track one candidate across a wide, horizontally-
            // scrolled row. group-hover: compiles to a compound selector
            // (`.group:hover .group-hover\:bg-x`), which always outranks a
            // plain `bg-x` utility regardless of source order, so this
            // correctly overrides the category shading on hover rather than
            // fighting with it.
            $hoverClass = 'group-hover:bg-zinc-200 dark:group-hover:bg-zinc-600';

            $columnLeftClass = fn (array $column): ?string => match (true) {
                $column['category_box'] && $column['is_category_start'] => $boxLeft,
                $column['is_judge_start'] => $judgeBoxLeft,
                default => null,
            };
            $columnRightClass = fn (array $column): ?string => match (true) {
                $column['category_box'] && $column['is_category_end'] => $boxRight,
                $column['is_judge_end'] => $judgeBoxRight,
                default => null,
            };
            $columnBottomClass = fn (array $column): ?string => match (true) {
                $column['category_box'] => $boxBottom,
                default => $judgeBoxBottom,
            };
        @endphp
        <div class="space-y-10">
            @foreach ($ensembleTables as $ensembleTable)
                <div class="space-y-8">
                    <flux:heading size="xl">{{ $ensembleTable['sectionLabel'] }} ({{ $ensembleTable['voicePartTables']->sum(fn (array $t): int => $t['rows']->count()) }})</flux:heading>

                    @foreach ($ensembleTable['voicePartTables'] as $table)
                        <div>
                            <flux:heading size="lg" class="mb-2">{{ $table['voicePart']->name }} ({{ $table['voicePart']->abbr }}) @ {{ $table['rows']->count() }}</flux:heading>

                            @if ($table['rows']->isEmpty())
                                <flux:text size="sm" class="text-zinc-500">No candidates for this Voice Part.</flux:text>
                            @else
                                <div class="overflow-x-auto">
                                    <flux:table>
                                        {{-- Three-row header: Score Category, then Judge (nested under its category, always boxed), then the factor abbreviation under each judge — which also carries the "Candidate #"/"Student / School / Teacher"/"VP" headers, since none of them belong to any category/judge group. Total/Result span all three rows. --}}
                                        <thead data-flux-columns>
                                            <tr>
                                                <th colspan="{{ ($confidential ? 1 : 0) + 2 }}" rowspan="2" class="{{ $headerCellClasses }} text-start"></th>
                                                @foreach ($table['categoryGroups'] as $group)
                                                    <th colspan="{{ $group['span'] }}" @class([$headerCellClasses, 'text-center', $boxTop => $group['box'], $boxLeft => $group['box'], $boxRight => $group['box'], $shadeClass => $group['shaded']])>{{ $group['label'] }}</th>
                                                @endforeach
                                                <th rowspan="3" class="{{ $headerCellClasses }} text-center">Total</th>
                                                <th rowspan="3" class="{{ $headerCellClasses }} text-center">Result</th>
                                            </tr>
                                            <tr>
                                                @foreach ($table['judgeGroups'] as $group)
                                                    <th colspan="{{ $group['span'] }}" @class([$headerCellClasses, 'text-center', $judgeBoxTop, $group['box'] && $group['is_category_start'] ? $boxLeft : $judgeBoxLeft, $group['box'] && $group['is_category_end'] ? $boxRight : $judgeBoxRight, $shadeClass => $group['shaded']])>{{ $group['label'] }}</th>
                                                @endforeach
                                            </tr>
                                            <tr>
                                                <th class="{{ $headerCellClasses }} text-start">Candidate #</th>
                                                @if ($confidential)
                                                    <th class="{{ $headerCellClasses }} text-start">Student / School / Teacher</th>
                                                @endif
                                                <th class="{{ $headerCellClasses }} text-center">VP</th>
                                                @foreach ($table['columns'] as $column)
                                                    <th @class([$headerCellClasses, 'text-center', $columnLeftClass($column), $columnRightClass($column), $shadeClass => $column['category_shaded']])>{{ $column['label'] }}</th>
                                                @endforeach
                                            </tr>
                                        </thead>

                                        <flux:table.rows>
                                            @foreach ($table['rows'] as $row)
                                                <flux:table.row :key="$row['candidate']->id" class="group">
                                                    <flux:table.cell @class(['font-medium tabular-nums', $hoverClass])>{{ $row['candidate']->id }}</flux:table.cell>
                                                    @if ($confidential)
                                                        <flux:table.cell @class([$hoverClass])>
                                                            <div>{{ $row['candidate']->student->user->sort_name }}</div>
                                                            <div class="pl-3 text-xs text-zinc-500">{{ \Illuminate\Support\Str::limit($row['candidate']->school->name ?? '—', 25) }}</div>
                                                            <div class="pl-3 text-xs text-zinc-500">{{ $row['candidate']->teacher->user->name }}</div>
                                                        </flux:table.cell>
                                                    @endif
                                                    <flux:table.cell align="center" @class([$hoverClass])>{{ $row['candidate']->voicePart->abbr }}</flux:table.cell>
                                                    @foreach ($table['columns'] as $column)
                                                        <flux:table.cell align="center" @class(['tabular-nums', $columnLeftClass($column), $columnRightClass($column), $columnBottomClass($column) => $loop->parent->last, $shadeClass => $column['category_shaded'], $hoverClass])>{{ $row['scores']["{$column['judge_id']}:{$column['score_factor_id']}"] ?? '—' }}</flux:table.cell>
                                                    @endforeach
                                                    <flux:table.cell align="center" @class(['font-medium tabular-nums', $hoverClass])>{{ $row['total'] }}</flux:table.cell>
                                                    <flux:table.cell align="center" @class([$hoverClass])>{{ $row['result'] }}</flux:table.cell>
                                                </flux:table.row>
                                            @endforeach
                                        </flux:table.rows>
                                    </flux:table>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif
</div>
