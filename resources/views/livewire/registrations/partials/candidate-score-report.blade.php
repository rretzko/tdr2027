{{-- One Candidate's public score-grid report (Results page's Per-School/
     Per-Person reports) — same category/judge-grouped table shape as the
     Tab Room Manager's Combined Audition Scores (Public) report
     (livewire.events.tab-room.reports.combined-audition-scores), narrowed
     to a single row and headed by the candidate's own name + voice part,
     since the teacher already knows whose report this is. Never shows
     identity columns within the grid itself — the header is the only place
     a name appears, keeping one consistent "public" grid shape across every
     report this data feeds. --}}
@php
    $headerCellClasses = 'py-3 px-3 first:ps-0 last:pe-0 text-sm font-medium text-zinc-800 dark:text-white border-b border-zinc-800/10 dark:border-white/20';
    $boxLeft = 'border-l-2 border-l-zinc-400 dark:border-l-zinc-500';
    $boxRight = 'border-r-2 border-r-zinc-400 dark:border-r-zinc-500';
    $boxTop = 'border-t-2 border-t-zinc-400 dark:border-t-zinc-500';
    $boxBottom = 'border-b-2 border-b-zinc-400 dark:border-b-zinc-500';
    $judgeBoxLeft = 'border-l border-l-zinc-300 dark:border-l-zinc-600';
    $judgeBoxRight = 'border-r border-r-zinc-300 dark:border-r-zinc-600';
    $judgeBoxTop = 'border-t border-t-zinc-300 dark:border-t-zinc-600';
    $judgeBoxBottom = 'border-b border-b-zinc-300 dark:border-b-zinc-600';
    $shadeClass = 'bg-zinc-100 dark:bg-zinc-700';

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
    $row = $table['rows']->first();
@endphp

<div>
    <flux:heading size="lg">{{ $candidate->student->user->name }}</flux:heading>
    <flux:subheading>{{ $candidate->voicePart->name }}</flux:subheading>

    <div class="overflow-x-auto mt-3">
        <flux:table>
            <thead data-flux-columns>
                <tr>
                    <th colspan="2" rowspan="2" class="{{ $headerCellClasses }} text-start"></th>
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
                    <th class="{{ $headerCellClasses }} text-center">VP</th>
                    @foreach ($table['columns'] as $column)
                        <th @class([$headerCellClasses, 'text-center', $columnLeftClass($column), $columnRightClass($column), $shadeClass => $column['category_shaded']])>{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>

            <flux:table.rows>
                <flux:table.row :key="$candidate->id">
                    <flux:table.cell class="font-medium tabular-nums">{{ $candidate->id }}</flux:table.cell>
                    <flux:table.cell align="center">{{ $candidate->voicePart->abbr }}</flux:table.cell>
                    @foreach ($table['columns'] as $column)
                        <flux:table.cell align="center" @class(['tabular-nums', $columnLeftClass($column), $columnRightClass($column), $column['category_box'] ? $boxBottom : $judgeBoxBottom, $shadeClass => $column['category_shaded']])>{{ $row['scores']["{$column['judge_id']}:{$column['score_factor_id']}"] ?? '—' }}</flux:table.cell>
                    @endforeach
                    <flux:table.cell align="center" class="font-medium tabular-nums">{{ $row['total'] }}</flux:table.cell>
                    <flux:table.cell align="center">{{ $row['result'] }}</flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>
    </div>
</div>
