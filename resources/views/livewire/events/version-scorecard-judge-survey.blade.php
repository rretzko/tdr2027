@php
    /** @var array $survey  see JudgeSurveySummaryService::forVersion() */
    $focusColors = [
        'fresh' => 'bg-green-500 dark:bg-green-400',
        'steady' => 'bg-sky-500 dark:bg-sky-400',
        'tiring' => 'bg-amber-500 dark:bg-amber-400',
        'running_on_empty' => 'bg-red-500 dark:bg-red-400',
    ];
    $percent = fn (int $count, int $of): int => $of > 0 ? (int) round($count / $of * 100) : 0;
    $bars = [
        'Anything getting in the way?' => [$survey['obstacles']['respondents'], 'judges answered', collect(\App\Enums\JudgeSurvey\JudgeObstacle::cases())->mapWithKeys(fn ($c) => [$c->label() => $survey['obstacles']['counts'][$c->value]])],
        'Listening on' => [$survey['listening']['respondents'], 'judges answered', collect(\App\Enums\JudgeSurvey\ListeningDevice::cases())->mapWithKeys(fn ($c) => [$c->label() => $survey['listening']['counts'][$c->value]])],
        'Scoring on (detected)' => [$survey['devices']['judges'], 'judges', collect(\App\Enums\JudgeSurvey\JudgeDeviceType::cases())->mapWithKeys(fn ($c) => [$c->label() => $survey['devices']['counts'][$c->value]])],
        'Would judge again' => [$survey['judgeAgain']['respondents'], 'judges answered', collect(\App\Enums\JudgeSurvey\JudgeAgain::cases())->mapWithKeys(fn ($c) => [$c->label() => $survey['judgeAgain']['counts'][$c->value]])],
    ];
@endphp

<div>
    <flux:heading size="lg" class="mb-1">Judge check-ins</flux:heading>
    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mb-3 max-w-3xl">
        At one-third, two-thirds and one-to-go, each judge gets a congratulations and a short optional survey. Answers are combined and never shown by judge.
        @unless ($survey['enabled'])
            Check-ins are turned off for this Version, so no new ones are being shown.
        @endunless
    </flux:text>

    @if ($survey['shown'] === 0)
        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">No judge has reached a check-in yet.</flux:text>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-4">
            <flux:card class="space-y-2">
                <flux:heading size="sm">Response rate</flux:heading>
                <div class="text-3xl font-semibold tabular-nums text-zinc-800 dark:text-white">{{ $survey['responseRate'] }}%</div>
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                    {{ number_format($survey['answered']) }} of {{ number_format($survey['shown']) }} check-ins answered, by {{ number_format($survey['respondents']) }} {{ \Illuminate\Support\Str::plural('judge', $survey['respondents']) }}.
                </flux:text>
            </flux:card>

            @if ($survey['visible'])
                <flux:card class="space-y-2">
                    <flux:heading size="sm">Overall rating</flux:heading>
                    <div class="text-3xl font-semibold tabular-nums text-zinc-800 dark:text-white">{{ $survey['overall']['average'] === null ? '—' : number_format($survey['overall']['average'], 1).' / 5' }}</div>
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                        @if ($survey['overall']['respondents'] === 0)
                            Asked at the last check-in, one candidate from the end.
                        @else
                            From {{ number_format($survey['overall']['respondents']) }} {{ \Illuminate\Support\Str::plural('judge', $survey['overall']['respondents']) }} at their last check-in.
                        @endif
                    </flux:text>
                </flux:card>
            @endif
        </div>

        @unless ($survey['visible'])
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                Answers appear once {{ \App\Services\JudgeSurveySummaryService::MIN_RESPONDENTS }} judges have answered, so no single judge's answers can be picked out.
            </flux:text>
        @else
            {{-- Focus by checkpoint: the fatigue trend --}}
            <flux:card class="mb-4 space-y-3">
                <flux:heading size="sm">Focus as judging goes on</flux:heading>
                @foreach ($survey['checkpoints'] as $row)
                    <div wire:key="focus-{{ $row['checkpoint']->value }}">
                        <div class="flex items-baseline justify-between gap-2 text-sm">
                            <span class="font-medium">{{ $row['checkpoint']->label() }}</span>
                            <span class="tabular-nums text-zinc-500 dark:text-zinc-400">{{ $row['focusAnswers'] }} {{ \Illuminate\Support\Str::plural('answer', $row['focusAnswers']) }}</span>
                        </div>
                        @if ($row['focusAnswers'] > 0)
                            <div class="mt-1 flex h-4 w-full overflow-hidden rounded bg-zinc-100 dark:bg-zinc-800">
                                @foreach ($row['focus'] as $value => $count)
                                    @if ($count > 0)
                                        <div class="{{ $focusColors[$value] }}" style="width: {{ $percent($count, $row['focusAnswers']) }}%" title="{{ \App\Enums\JudgeSurvey\JudgeFocus::from($value)->label() }}: {{ $count }}"></div>
                                    @endif
                                @endforeach
                            </div>
                        @else
                            <div class="mt-1 h-4 w-full rounded bg-zinc-100 dark:bg-zinc-800"></div>
                        @endif
                    </div>
                @endforeach
                <div class="flex flex-wrap gap-3 pt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    @foreach (\App\Enums\JudgeSurvey\JudgeFocus::cases() as $focus)
                        <span class="inline-flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm {{ $focusColors[$focus->value] }}"></span>{{ $focus->label() }}</span>
                    @endforeach
                </div>
            </flux:card>

            <div class="grid gap-4 md:grid-cols-2 mb-4">
                @foreach ($bars as $title => [$total, $totalLabel, $counts])
                    <flux:card class="space-y-2" wire:key="survey-bars-{{ \Illuminate\Support\Str::slug($title) }}">
                        <div class="flex items-baseline justify-between gap-2">
                            <flux:heading size="sm">{{ $title }}</flux:heading>
                            <span class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ $total }} {{ $totalLabel }}</span>
                        </div>
                        @foreach ($counts as $label => $count)
                            <div class="text-sm">
                                <div class="flex justify-between gap-2">
                                    <span>{{ $label }}</span>
                                    <span class="tabular-nums text-zinc-500 dark:text-zinc-400">{{ $count }}</span>
                                </div>
                                <div class="mt-0.5 h-1.5 w-full rounded bg-zinc-100 dark:bg-zinc-800">
                                    <div class="h-1.5 rounded bg-zinc-500 dark:bg-zinc-300" style="width: {{ $percent($count, $total) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </flux:card>
                @endforeach
            </div>

            @if ($survey['comments'] !== [])
                <flux:card class="space-y-3">
                    <flux:heading size="sm">Comments ({{ count($survey['comments']) }})</flux:heading>
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">In random order. At the last check-in judges were asked for one change that would have made judging better.</flux:text>
                    <ul class="space-y-2">
                        @foreach ($survey['comments'] as $comment)
                            <li class="rounded-md border border-zinc-200 dark:border-zinc-700 px-3 py-2 text-sm">
                                <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $comment['checkpoint']->label() }}</span>
                                {{ $comment['text'] }}
                            </li>
                        @endforeach
                    </ul>
                </flux:card>
            @endif
        @endunless
    @endif
</div>
