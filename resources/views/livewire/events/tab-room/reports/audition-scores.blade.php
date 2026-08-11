<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.tab-room.reports.index', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Reports</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Audition Scores</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">Audition Scores</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — per-judge, per-factor scores for one Voice Part</flux:text>
        </div>

        @if ($voicePartId !== null)
            <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('events.versions.tab-room.reports.audition-scores.pdf', ['version' => $version, 'voice_part_id' => $voicePartId])" target="_blank">
                Export PDF
            </flux:button>
        @endif
    </div>

    <div class="mb-4 max-w-xs">
        <flux:select wire:model.live="voicePartId" placeholder="Choose a Voice Part...">
            @foreach ($voiceParts as $voicePart)
                <flux:select.option value="{{ $voicePart->id }}">{{ $voicePart->name }} ({{ $voicePart->abbr }})</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($voicePartId === null)
        <flux:callout variant="info" icon="information-circle">
            <flux:callout.text>Choose a Voice Part to see its score table.</flux:callout.text>
        </flux:callout>
    @elseif ($rows->isEmpty())
        <flux:callout variant="info" icon="information-circle">
            <flux:callout.text>No candidates found for this Voice Part.</flux:callout.text>
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
        <div class="overflow-x-auto">
            <flux:table>
                {{-- Three-row header: Score Category, then Judge (each nested under its category, always boxed), then the factor abbreviation under each judge — which also carries the "Candidate #" header, since it doesn't belong to any category/judge group. Total/Result span all three rows. --}}
                <thead data-flux-columns>
                    <tr>
                        <th rowspan="2" class="{{ $headerCellClasses }} text-start"></th>
                        @foreach ($categoryGroups as $group)
                            <th colspan="{{ $group['span'] }}" @class([$headerCellClasses, 'text-center', $boxTop => $group['box'], $boxLeft => $group['box'], $boxRight => $group['box'], $shadeClass => $group['shaded']])>{{ $group['label'] }}</th>
                        @endforeach
                        <th rowspan="3" class="{{ $headerCellClasses }} text-center">Total</th>
                        <th rowspan="3" class="{{ $headerCellClasses }} text-center">Result</th>
                    </tr>
                    <tr>
                        @foreach ($judgeGroups as $group)
                            <th colspan="{{ $group['span'] }}" @class([$headerCellClasses, 'text-center', $judgeBoxTop, $group['box'] && $group['is_category_start'] ? $boxLeft : $judgeBoxLeft, $group['box'] && $group['is_category_end'] ? $boxRight : $judgeBoxRight, $shadeClass => $group['shaded']])>{{ $group['label'] }}</th>
                        @endforeach
                    </tr>
                    <tr>
                        <th class="{{ $headerCellClasses }} text-start">Candidate #</th>
                        @foreach ($columns as $column)
                            <th @class([$headerCellClasses, 'text-center', $columnLeftClass($column), $columnRightClass($column), $shadeClass => $column['category_shaded']])>{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>

                <flux:table.rows>
                    @foreach ($rows as $row)
                        <flux:table.row :key="$row['candidate']->id" class="group">
                            <flux:table.cell @class(['font-medium tabular-nums', $hoverClass])>{{ $row['candidate']->id }}</flux:table.cell>
                            @foreach ($columns as $column)
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
