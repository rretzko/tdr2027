@php
    /** @var list<array<string, mixed>> $measures  see JudgeMeasuresService::forVersion() */
    $signed = fn (?float $value): string => $value === null ? '—' : ($value > 0 ? '+' : '').number_format($value, 2);
    $percent = fn (?int $value): string => $value === null ? '—' : $value.'%';
    $r = fn (?float $value): string => $value === null ? '—' : number_format($value, 2);
@endphp

<div class="mt-10">
    <flux:heading size="lg" class="mb-1">How the judges scored</flux:heading>
    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 max-w-3xl mb-4">
        Each judge is compared with their co-judges on the same candidates, using full sheets only. This is information for planning, not a verdict on any judge.
        <strong>Lean</strong> is the judge's average total against their co-judges' average, positive when more favorable.
        <strong>Top two</strong> is the share of factor scores on the judge's two most-used values, and <strong>Same on every factor</strong> the share of sheets with one score throughout.
        <strong>Drift</strong> is the change in lean from the first third of the judge's roster to the last, in the order they scored it.
        <strong>Revised later</strong> counts sheets edited more than 10 minutes after the first save; <strong>Reopened</strong> and <strong>Replays</strong> count candidates opened and recordings played again after saving. Scores before each edit aren't kept, so how far they moved isn't known.
    </flux:text>

    <div class="space-y-6">
        @foreach ($measures as $room)
            @php $tolerance = $room['tolerance']; @endphp
            <flux:card class="space-y-4" wire:key="judge-measures-{{ $room['room']['id'] }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="sm">{{ $room['room']['name'] }}</flux:heading>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:text size="sm" class="tabular-nums">
                            Tolerance {{ $tolerance['value'] ?? 'none' }} · largest gap {{ $tolerance['largestGap'] ?? '—' }} · {{ $tolerance['outside'] }} outside
                        </flux:text>
                        @if ($tolerance['loose'])
                            <flux:badge size="sm" color="amber" icon="exclamation-triangle">Loose: {{ $tolerance['value'] }} of a {{ $tolerance['judgeTotalRange'] }}-point range</flux:badge>
                        @endif
                    </div>
                </div>

                {{-- Judge pairs --}}
                <div>
                    <flux:text size="sm" class="font-medium mb-1">Agreement between judges</flux:text>
                    <div class="space-y-1">
                        @foreach ($room['pairs'] as $pair)
                            <div wire:key="pair-{{ $room['room']['id'] }}-{{ $loop->index }}" class="flex flex-wrap items-baseline justify-between gap-x-4 text-sm">
                                <span class="text-zinc-600 dark:text-zinc-300">{{ $pair['judges'][0] }} &amp; {{ $pair['judges'][1] }}</span>
                                <span class="tabular-nums text-zinc-800 dark:text-white">
                                    r {{ $r($pair['r']) }} · average gap {{ $pair['averageGap'] ?? '—' }} · largest {{ $pair['largestGap'] ?? '—' }}
                                    <span class="text-zinc-500 dark:text-zinc-400">({{ $pair['candidates'] }} candidates)</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Below md: cards --}}
                <div class="space-y-3 md:hidden">
                    @foreach ($room['judges'] as $judge)
                        <div wire:key="m-jm-{{ $room['room']['id'] }}-{{ $judge['id'] }}" class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3 space-y-1">
                            <div class="flex items-center justify-between gap-2">
                                <flux:text class="font-medium">{{ $judge['name'] }}</flux:text>
                                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ $judge['type'] }}</flux:text>
                            </div>
                            <flux:text size="sm" class="tabular-nums">Lean {{ $signed($judge['calibration']['lean']) }} · drift {{ $signed($judge['drift']['change']) }} · {{ $judge['sheets'] }} sheets</flux:text>
                            <flux:text size="sm" class="tabular-nums">Used {{ $judge['scaleUse']['valuesUsed'] }} of {{ $judge['scaleUse']['valuesPossible'] }} values · top two {{ $percent($judge['scaleUse']['topTwoShare']) }} · same on every factor {{ $percent($judge['scaleUse']['sameEveryFactor']) }}</flux:text>
                            <flux:text size="sm" class="tabular-nums text-zinc-500 dark:text-zinc-400">Revised later {{ $judge['review']['revised'] }} · reopened {{ $judge['review']['reopened'] }} · replays {{ $judge['review']['replays'] }}</flux:text>
                        </div>
                    @endforeach
                </div>

                {{-- md+: table --}}
                <flux:table class="hidden md:table">
                    <flux:table.columns>
                        <flux:table.column>Judge</flux:table.column>
                        <flux:table.column align="end">Sheets</flux:table.column>
                        <flux:table.column align="end">Lean</flux:table.column>
                        <flux:table.column align="end">Drift</flux:table.column>
                        <flux:table.column align="end">Values used</flux:table.column>
                        <flux:table.column align="end">Top two</flux:table.column>
                        <flux:table.column align="end">Same on every factor</flux:table.column>
                        <flux:table.column align="end">Revised later</flux:table.column>
                        <flux:table.column align="end">Reopened</flux:table.column>
                        <flux:table.column align="end">Replays</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($room['judges'] as $judge)
                            <flux:table.row wire:key="jm-{{ $room['room']['id'] }}-{{ $judge['id'] }}">
                                <flux:table.cell>
                                    {{ $judge['name'] }}
                                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $judge['type'] }}</span>
                                </flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $judge['sheets'] }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $signed($judge['calibration']['lean']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $signed($judge['drift']['change']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $judge['scaleUse']['valuesUsed'] }} / {{ $judge['scaleUse']['valuesPossible'] }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $percent($judge['scaleUse']['topTwoShare']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $percent($judge['scaleUse']['sameEveryFactor']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $judge['review']['revised'] }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $judge['review']['reopened'] }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $judge['review']['replays'] }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @endforeach
    </div>
</div>
