@php
    /** @var array|null $selection  see SelectionAnalysisService::forVersion() */
    // Same validated categorical slots as the other Scorecard charts:
    // accepted = 1 (blue), not accepted = 2 (orange).
    $seriesColors = [
        'accepted' => ['bar' => 'text-[#2a78d6] dark:text-[#3987e5]', 'dot' => 'bg-[#2a78d6] dark:bg-[#3987e5]', 'label' => 'Accepted'],
        'missed' => ['bar' => 'text-[#eb6834] dark:text-[#d95926]', 'dot' => 'bg-[#eb6834] dark:bg-[#d95926]', 'label' => 'Not accepted'],
    ];

    $lineName = fn (array $line): string => $line['voicePart']['abbr'].($line['ensemble'] === null ? '' : ' · '.$line['ensemble']['name']);
    $points = fn (?int $value): string => $value === null ? '—' : $value.' '.\Illuminate\Support\Str::plural('pt', $value);
    $median = fn (?float $value): string => $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.');
@endphp

@if ($selection === null)
    <flux:card class="text-center py-10">
        <flux:heading size="lg">No selections yet</flux:heading>
        <flux:text size="sm" class="text-zinc-500 mt-1">This tab fills in once Ensemble Cut-offs has accepted or not accepted candidates.</flux:text>
    </flux:card>
@else
    @php
        $outcomes = $selection['outcomes'];
        $lines = collect($selection['lines']);
        $tied = $lines->filter(fn (array $line): bool => $line['tiedMissed'] > 0);
        $derivedLines = $lines->contains(fn (array $line): bool => $line['lineSource'] === 'lowest_accepted');
        $betterWord = $selection['higherIsBetter'] ? 'higher' : 'lower';
    @endphp

    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 max-w-3xl mb-6">
        How the cut-offs fell, from the totals each decision was made on.
        @if ($selection['strategyLabel'] !== null)
            Cut-off strategy: {{ $selection['strategyLabel'] }}.
        @endif
        {{ ucfirst($betterWord) }} totals are better in this Version.
    </flux:text>

    {{-- Outcomes and Ensembles --}}
    <div class="grid gap-4 xl:grid-cols-3 mb-10">
        <flux:card class="space-y-3">
            <flux:heading size="sm">Outcomes</flux:heading>
            <dl class="space-y-2">
                @foreach (array_filter([
                    ['Decided', number_format($outcomes['decided'])],
                    ['Accepted', number_format($outcomes['accepted']).' ('.$outcomes['acceptRate'].'%)'],
                    ['Not accepted', number_format($outcomes['notAccepted'])],
                    $outcomes['noShow'] > 0 ? ['No show', number_format($outcomes['noShow'])] : null,
                    $outcomes['incomplete'] > 0 ? ['Incomplete', number_format($outcomes['incomplete'])] : null,
                    $outcomes['declined'] > 0 ? ['Declined after acceptance', number_format($outcomes['declined'])] : null,
                ]) as [$label, $value])
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                        <dd class="text-lg font-semibold tabular-nums text-zinc-800 dark:text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </flux:card>

        <flux:card class="space-y-3 xl:col-span-2 @container">
            <flux:heading size="sm">Accepted by Ensemble</flux:heading>
            {{-- Counts beside the pies when the card itself is wide (container query), pies below otherwise. --}}
            <div class="flex flex-col @2xl:flex-row @2xl:items-start gap-4">
            <dl class="space-y-2 @2xl:w-80 @2xl:shrink-0">
                @foreach ($selection['ensembles'] as $ensemble)
                    <div wire:key="sel-ensemble-{{ $ensemble['id'] }}" class="flex items-baseline justify-between gap-3">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $ensemble['name'] }}</dt>
                        <dd class="text-right">
                            <span class="text-lg font-semibold tabular-nums text-zinc-800 dark:text-white">{{ number_format($ensemble['accepted']) }}</span>
                            @if ($ensemble['prior'] !== null)
                                @php $change = $ensemble['accepted'] - $ensemble['prior']; @endphp
                                <span class="text-sm tabular-nums text-zinc-500 dark:text-zinc-400 ms-2">
                                    {{ number_format($ensemble['prior']) }} in {{ $ensemble['priorYear'] }} ({{ $change > 0 ? '+' : '' }}{{ $change }})
                                </span>
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>

            @if ($pieCharts->sum('total') > 0)
                <div class="pt-3 border-t border-zinc-200 dark:border-zinc-700 @2xl:pt-0 @2xl:border-t-0 @2xl:ps-6 @2xl:border-s @2xl:flex-1 min-w-0">
                    <x-ensemble-pie-charts :charts="$pieCharts" />
                </div>
            @endif
            </div>
        </flux:card>
    </div>

    @if ($lines->isEmpty())
        <flux:callout icon="information-circle" class="mb-6">
            <flux:callout.text>No scores were recorded for this Version's decisions (imported Versions didn't bring their scores across), so there's no cut-off analysis.</flux:callout.text>
        </flux:callout>
    @else
        @if ($tied->isNotEmpty())
            <flux:callout icon="exclamation-triangle" color="amber" class="mb-6">
                <flux:callout.heading>Candidates at or past a line weren't accepted</flux:callout.heading>
                <flux:callout.text>
                    {{ $tied->map(fn (array $line): string => $lineName($line).': '.$line['tiedMissed'])->implode('; ') }}.
                    Check these voice parts on Ensemble Cut-offs. A score edited after the cut-off was drawn is the usual cause.
                </flux:callout.text>
            </flux:callout>
        @endif

        {{-- Cut-off lines --}}
        <flux:heading size="lg" class="mb-1">How close the cut-offs were</flux:heading>
        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 max-w-3xl mb-3">
            One row per cut-off line. "Near the line" counts candidates within 3% of the line's score (at least 1 point) on each side.
            "Closest miss" is how far the best candidate not accepted was from the line.
            @if ($derivedLines)
                Rows marked * have no stored cut-off score, so the line shown is the worst accepted total.
            @endif
        </flux:text>

        {{-- Below md: cards --}}
        <div class="space-y-3 md:hidden mb-10">
            @foreach ($lines as $line)
                <flux:card class="space-y-1" wire:key="m-sel-line-{{ $line['key'] }}">
                    <div class="flex items-center justify-between gap-2">
                        <flux:heading size="sm">{{ $lineName($line) }}</flux:heading>
                        <flux:text size="sm" class="tabular-nums">Line {{ $line['line'] }}{{ $line['lineSource'] === 'lowest_accepted' ? '*' : '' }}</flux:text>
                    </div>
                    <flux:text size="sm" class="tabular-nums">{{ $line['accepted'] }} of {{ $line['pool'] }} accepted ({{ $line['acceptRate'] }}%) · closest miss {{ $points($line['closestMiss']) }}</flux:text>
                    <flux:text size="sm" class="tabular-nums text-zinc-500 dark:text-zinc-400">Near the line (±{{ $line['near'] }}): {{ $line['nearAccepted'] }} accepted, {{ $line['nearMissed'] }} not · medians {{ $median($line['acceptedMedian']) }} / {{ $median($line['missedMedian']) }}</flux:text>
                </flux:card>
            @endforeach
        </div>

        {{-- md+: table --}}
        <flux:table class="hidden md:table mb-10">
            <flux:table.columns>
                <flux:table.column>Voice part</flux:table.column>
                @if ($selection['perEnsemble'])
                    <flux:table.column>Ensemble</flux:table.column>
                @endif
                <flux:table.column align="end">Line</flux:table.column>
                <flux:table.column align="end">Accepted</flux:table.column>
                <flux:table.column align="end">Rate</flux:table.column>
                <flux:table.column align="end">Closest miss</flux:table.column>
                <flux:table.column align="end">Near: accepted</flux:table.column>
                <flux:table.column align="end">Near: not accepted</flux:table.column>
                <flux:table.column align="end">Median accepted</flux:table.column>
                <flux:table.column align="end">Median not accepted</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($lines as $line)
                    <flux:table.row wire:key="sel-line-{{ $line['key'] }}">
                        <flux:table.cell>{{ $line['voicePart']['abbr'] }}</flux:table.cell>
                        @if ($selection['perEnsemble'])
                            <flux:table.cell>{{ $line['ensemble']['name'] ?? '—' }}</flux:table.cell>
                        @endif
                        <flux:table.cell align="end" class="tabular-nums">{{ $line['line'] }}{{ $line['lineSource'] === 'lowest_accepted' ? '*' : '' }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $line['accepted'] }} / {{ $line['pool'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $line['acceptRate'] }}%</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $points($line['closestMiss']) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $line['nearAccepted'] }} <span class="text-zinc-400 dark:text-zinc-500">±{{ $line['near'] }}</span></flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $line['nearMissed'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $median($line['acceptedMedian']) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $median($line['missedMedian']) }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        {{-- Score distribution: one row per cut-off line on a shared score axis --}}
        @php
            $ends = $lines->flatMap(fn (array $line): array => array_merge($line['acceptedRange'] ?? [], $line['missedRange'] ?? [], [$line['line']]));
            $span = max(1, (int) $ends->max() - (int) $ends->min());
            $step = collect([1, 2, 5, 10, 20, 25, 50, 100, 200, 500])->first(fn (int $candidate): bool => $span / $candidate <= 8) ?? 1000;
            $axisMin = intdiv((int) $ends->min(), $step) * $step;
            $axisMax = (int) (ceil((int) $ends->max() / $step) * $step);
            $axisSpan = max(1, $axisMax - $axisMin);
            $pos = fn (int $value): float => round(($value - $axisMin) / $axisSpan * 100, 3);
            $ticks = range($axisMin, $axisMax, $step);
        @endphp

        <flux:heading size="lg" class="mb-1">Score distribution</flux:heading>
        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 max-w-3xl mb-3">
            Each bar runs from the lowest to the highest total on that side of the line, and the dark mark is the cut-off.
            {{ ucfirst($betterWord) }} totals are better, so accepted candidates sit to the {{ $selection['higherIsBetter'] ? 'right' : 'left' }}.
        </flux:text>

        <flux:card class="mb-10 space-y-4">
            <div class="flex flex-wrap gap-x-6 gap-y-2">
                @foreach ($seriesColors as $series)
                    <div class="flex items-center gap-2">
                        <span class="inline-block h-2.5 w-5 rounded-sm {{ $series['dot'] }}"></span>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $series['label'] }}</span>
                    </div>
                @endforeach
                <div class="flex items-center gap-2">
                    <span class="inline-block h-4 w-0.5 bg-zinc-800 dark:bg-white"></span>
                    <span class="text-sm text-zinc-500 dark:text-zinc-400">Cut-off</span>
                </div>
            </div>

            <div class="space-y-3 md:space-y-2">
                @foreach ($lines as $line)
                    @php
                        $describe = $lineName($line).': cut-off '.$line['line']
                            .($line['acceptedRange'] !== null ? '; accepted '.$line['accepted'].' from '.$line['acceptedRange'][0].' to '.$line['acceptedRange'][1] : '')
                            .($line['missedRange'] !== null ? '; not accepted '.$line['missed'].' from '.$line['missedRange'][0].' to '.$line['missedRange'][1] : '; none not accepted');
                    @endphp
                    <div wire:key="sel-range-{{ $line['key'] }}" class="grid grid-cols-1 md:grid-cols-[12rem_1fr] md:items-center gap-x-3 gap-y-1" title="{{ $describe }}">
                        <div class="text-sm text-zinc-600 dark:text-zinc-300 truncate">{{ $lineName($line) }}</div>
                        <div class="relative h-7" role="img" aria-label="{{ $describe }}">
                            <div class="absolute inset-0 rounded bg-zinc-100 dark:bg-zinc-800"></div>
                            @if ($line['acceptedRange'] !== null)
                                <div class="absolute top-1 h-2.5 rounded-sm bg-[#2a78d6] dark:bg-[#3987e5]"
                                     style="left: {{ $pos($line['acceptedRange'][0]) }}%; width: max(3px, {{ $pos($line['acceptedRange'][1]) - $pos($line['acceptedRange'][0]) }}%);"></div>
                            @endif
                            @if ($line['missedRange'] !== null)
                                <div class="absolute bottom-1 h-2.5 rounded-sm bg-[#eb6834] dark:bg-[#d95926]"
                                     style="left: {{ $pos($line['missedRange'][0]) }}%; width: max(3px, {{ $pos($line['missedRange'][1]) - $pos($line['missedRange'][0]) }}%);"></div>
                            @endif
                            <div class="absolute -top-0.5 -bottom-0.5 w-0.5 -translate-x-1/2 bg-zinc-800 dark:bg-white" style="left: {{ $pos($line['line']) }}%;"></div>
                        </div>
                    </div>
                @endforeach

                {{-- Shared score axis --}}
                <div class="grid grid-cols-1 md:grid-cols-[12rem_1fr] gap-x-3">
                    <div class="hidden md:block"></div>
                    <div class="relative h-5 border-t border-zinc-300 dark:border-zinc-600">
                        @foreach ($ticks as $tick)
                            <span class="absolute top-1 -translate-x-1/2 text-xs tabular-nums text-zinc-500 dark:text-zinc-400" style="left: {{ $pos($tick) }}%;">{{ $tick }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </flux:card>
    @endif
@endif
