@php
    /** @var array $progress  see AdjudicationProgressService::forVersion() */
    $window = $progress['window'];
    $totals = $progress['totals'];

    $duration = function (?int $minutes): string {
        if ($minutes === null) {
            return '—';
        }
        if ($minutes < 60) {
            return $minutes.'m';
        }

        return intdiv($minutes, 60).'h'.($minutes % 60 > 0 ? ' '.($minutes % 60).'m' : '');
    };

    $pace = fn (?int $seconds): string => $seconds === null ? '—' : ($seconds < 60 ? $seconds.'s' : intdiv($seconds, 60).'m '.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT).'s');

    $when = fn (?\Illuminate\Support\Carbon $at): string => $at === null ? '—' : $at->copy()->forDisplay()->format('D M j, g:i a');

    $headline = match ($window['status']) {
        'open' => 'Adjudication is open — '.$window['daysLeft'].' '.\Illuminate\Support\Str::plural('day', (int) $window['daysLeft']).' left',
        'upcoming' => "Adjudication hasn't opened yet",
        'closed' => 'The adjudication window has closed',
        default => 'No adjudication window is set',
    };

    $statusBadge = [
        'complete' => ['Complete', 'green'],
        'in_progress' => ['In progress', 'blue'],
        'just_started' => ['Just started', 'amber'],
        'not_started' => ['Not started', 'red'],
        'not_scoring' => ['Not scoring', 'zinc'],
        'no_candidates' => ['No candidates', 'zinc'],
    ];

    $attention = collect($progress['rooms'])
        ->flatMap(fn (array $room): array => array_map(fn (array $judge): array => [...$judge, 'roomName' => $room['room']->name], $room['judges']))
        ->filter(fn (array $judge): bool => $judge['needsAttention'])
        ->sortBy(fn (array $judge): array => [['not_started' => 0, 'just_started' => 1][$judge['status']] ?? 2, $judge['roomName']])
        ->values();

    $schedule = $progress['schedule'];
    $scheduleCallout = [
        'on_track' => ['On track to finish', 'green', 'check-circle'],
        'at_risk' => ['At risk of running late', 'amber', 'exclamation-triangle'],
        'behind' => ['Behind schedule', 'red', 'exclamation-triangle'],
    ][$schedule['status']] ?? null;

    $listeningTracked = $progress['listeningTracked'];
    $shortListeners = collect($progress['rooms'])
        ->flatMap(fn (array $room): array => array_map(fn (array $judge): array => [...$judge, 'roomName' => $room['room']->name], $room['judges']))
        ->filter(fn (array $judge): bool => $judge['listening']['short'] > 0)
        ->sortByDesc(fn (array $judge): int => $judge['listening']['short'])
        ->values();
    $shortThreshold = (int) round(\App\Services\AdjudicationProgressService::SHORT_LISTEN_RATIO * 100);

    // Judges with their own students (or their school's) in their Room —
    // information for the EM, not an accusation; see ownStudents() in the service.
    $ownStudentJudges = collect($progress['rooms'])
        ->flatMap(fn (array $room): array => array_map(fn (array $judge): array => [...$judge, 'roomName' => $room['room']->name], $room['judges']))
        ->filter(fn (array $judge): bool => $judge['ownStudents']['count'] > 0)
        ->sortBy(fn (array $judge): array => [$judge['roomName'], $judge['name']])
        ->values();

    $ownLabel = fn (array $own): string => $own['count'].' own '.\Illuminate\Support\Str::plural('student', $own['count']);

    $leanText = function (array $own): string {
        if ($own['lean'] === null) {
            return 'no co-judge scores to compare yet';
        }
        $points = abs($own['lean']);
        $compared = 'on '.$own['compared'].' compared';
        if ($points < 0.05) {
            return 'in line with co-judges '.$compared;
        }

        return number_format($points, 1).' '.(number_format($points, 1) === '1.0' ? 'point' : 'points').' '.($own['lean'] > 0 ? 'more favorable' : 'harsher').' than co-judges '.$compared;
    };

    $roomFlag = [
        'stalled' => ['No judge has started', 'red'],
        'uneven' => ['Waiting on judges', 'amber'],
    ];
@endphp

<div wire:poll.120s.visible class="space-y-8">
    {{-- Window --}}
    <flux:callout :icon="$window['status'] === 'open' ? 'clock' : 'calendar'" :color="$window['status'] === 'open' ? 'blue' : 'zinc'">
        <flux:callout.heading>
            {{ $headline }}
        </flux:callout.heading>
        <flux:callout.text>
            @if ($window['start'] !== null)
                {{ $when($window['start']) }}@if ($window['end'] !== null) through {{ $when($window['end']) }}@endif.
            @else
                Add an Adjudication date on the Version's dates to see the time remaining.
            @endif
            Progress refreshes every two minutes while this tab is open.
        </flux:callout.text>
    </flux:callout>

    {{-- Schedule: will the window finish at the recent pace? --}}
    @if ($scheduleCallout !== null)
        <flux:callout :icon="$scheduleCallout[2]" :color="$scheduleCallout[1]">
            <flux:callout.heading>{{ $scheduleCallout[0] }}</flux:callout.heading>
            <flux:callout.text>
                {{ number_format($schedule['remaining']) }} judge {{ \Illuminate\Support\Str::plural('scoring', $schedule['remaining']) }} remain, so judges need about
                <strong>{{ number_format($schedule['neededPerDay']) }} a day</strong> to finish by {{ $when($window['end']) }}.
                Over the last {{ $schedule['rateDays'] }} {{ \Illuminate\Support\Str::plural('day', $schedule['rateDays']) }} they've averaged
                <strong>{{ number_format($schedule['recentPerDay']) }} a day</strong>.
                @if ($schedule['status'] !== 'on_track')
                    @if ($schedule['projectedFinish'] !== null)
                        At that rate the last score would land around {{ $when($schedule['projectedFinish']) }}.
                    @else
                        No scoring has been completed recently, so there's no finish to project.
                    @endif
                    @if ($totals['judgesNotStarted'] > 0)
                        Getting the {{ $totals['judgesNotStarted'] }} {{ \Illuminate\Support\Str::plural('judge', $totals['judgesNotStarted']) }} who haven't started under way will help most.
                    @endif
                @endif
            </flux:callout.text>
        </flux:callout>
    @endif

    {{-- Headline tiles --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card class="space-y-2">
            <flux:heading size="sm">Overall progress</flux:heading>
            <div class="text-3xl font-semibold tabular-nums text-zinc-800 dark:text-white">{{ $totals['percent'] }}%</div>
            <x-adjudication-progress-bar :percent="$totals['percent']" />
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                {{ number_format($totals['completed']) }} of {{ number_format($totals['expected']) }} judge scorings entered
            </flux:text>
        </flux:card>

        <flux:card class="space-y-2">
            <flux:heading size="sm">Room auditions fully scored</flux:heading>
            <div class="text-3xl font-semibold tabular-nums text-zinc-800 dark:text-white">{{ number_format($totals['candidatesComplete']) }}</div>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                of {{ number_format($totals['candidates']) }} — scored by every judge in their Room. A student auditioning in two Rooms counts once per Room.
            </flux:text>
        </flux:card>

        <flux:card class="space-y-3">
            <flux:heading size="sm">Judges</flux:heading>
            <dl class="space-y-1">
                @foreach ([['Complete', $totals['judgesComplete']], ['Started', $totals['judges'] - $totals['judgesComplete'] - $totals['judgesNotStarted']], ['Not started', $totals['judgesNotStarted']], ['Need a nudge', $totals['judgesNeedingAttention']]] as [$label, $value])
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                        <dd class="text-lg font-semibold tabular-nums text-zinc-800 dark:text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </flux:card>

        <flux:card class="space-y-2">
            <flux:heading size="sm">Judging time remaining</flux:heading>
            <div class="text-3xl font-semibold tabular-nums text-zinc-800 dark:text-white">{{ $duration($totals['remainingMinutes']) }}</div>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                @if ($totals['paceSeconds'] !== null)
                    Combined across all judges. Typical pace is {{ $pace($totals['paceSeconds']) }} per candidate.
                @else
                    Shown once judges have scored enough candidates to measure a pace.
                @endif
            </flux:text>
        </flux:card>
    </div>

    {{-- Needs a nudge --}}
    <div>
        <flux:heading size="lg" class="mb-1">Judges who may need a nudge</flux:heading>
        <flux:text size="sm" class="text-zinc-500 mb-3 max-w-3xl">
            Not started; just started (under 10% of their Room) with no scoring in the last {{ \App\Services\AdjudicationProgressService::RECENT_HOURS }} hours; or no scoring in the last {{ \App\Services\AdjudicationProgressService::IDLE_HOURS }} hours.
            "Est. time left" uses the judge's own pace, or the typical pace if they haven't scored enough yet.
        </flux:text>

        @if ($attention->isEmpty())
            <flux:callout icon="check-circle" color="green">
                <flux:callout.heading>Every judge is under way</flux:callout.heading>
            </flux:callout>
        @else
            {{-- Mobile: cards --}}
            <div class="space-y-3 md:hidden">
                @foreach ($attention as $judge)
                    <flux:card class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <flux:heading size="sm">{{ $judge['name'] }}</flux:heading>
                            <flux:badge size="sm" :color="$statusBadge[$judge['status']][1]">{{ $judge['idle'] ? 'Idle' : $statusBadge[$judge['status']][0] }}</flux:badge>
                        </div>
                        <flux:text size="sm">{{ $judge['type'] }} · {{ $judge['roomName'] }}</flux:text>
                        <a href="mailto:{{ $judge['email'] }}" class="block text-xs font-normal text-blue-600 dark:text-blue-400 hover:underline select-all">{{ $judge['email'] }}</a>
                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                            {{ $judge['completed'] }} scored · {{ $judge['remaining'] }} to go · est. {{ $duration($judge['remainingMinutes']) }} · last {{ $when($judge['lastActivity']) }}
                        </flux:text>
                    </flux:card>
                @endforeach
            </div>

            {{-- md+: table --}}
            <flux:table class="hidden md:table">
                <flux:table.columns>
                    <flux:table.column>Judge</flux:table.column>
                    <flux:table.column>Room</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column align="end">Scored</flux:table.column>
                    <flux:table.column align="end">To go</flux:table.column>
                    <flux:table.column align="end">Est. time left</flux:table.column>
                    <flux:table.column>Last activity</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($attention as $judge)
                        <flux:table.row :key="'attn-'.$judge['judge']->id">
                            <flux:table.cell class="font-medium">{{ $judge['name'] }}<a href="mailto:{{ $judge['email'] }}" class="block text-xs font-normal text-blue-600 dark:text-blue-400 hover:underline select-all">{{ $judge['email'] }}</a><span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $judge['type'] }}</span></flux:table.cell>
                            <flux:table.cell class="whitespace-normal">{{ $judge['roomName'] }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$statusBadge[$judge['status']][1]">{{ $judge['idle'] ? 'Idle' : $statusBadge[$judge['status']][0] }}</flux:badge></flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $judge['completed'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $judge['remaining'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $duration($judge['remainingMinutes']) }}</flux:table.cell>
                            <flux:table.cell>{{ $when($judge['lastActivity']) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    {{-- Short listens --}}
    @if ($shortListeners->isNotEmpty())
        <flux:callout icon="speaker-wave" color="amber">
            <flux:callout.heading>Some scores were saved before the recording finished</flux:callout.heading>
            <flux:callout.text>
                Saves made before {{ $shortThreshold }}% of a candidate's recordings had played:
            </flux:callout.text>
            <ul class="mt-2 space-y-1 text-sm text-zinc-700 dark:text-zinc-300">
                @foreach ($shortListeners as $judge)
                    <li wire:key="short-{{ $judge['judge']->id }}">
                        <span class="font-medium">{{ $judge['name'] }}</span> ({{ $judge['roomName'] }}) —
                        {{ $judge['listening']['short'] }} of {{ $judge['listening']['measured'] }} measured {{ \Illuminate\Support\Str::plural('save', $judge['listening']['measured']) }},
                        {{ $judge['listening']['heardPercent'] }}% heard on average
                    </li>
                @endforeach
            </ul>
        </flux:callout>
    @endif

    {{-- Own students --}}
    @if ($ownStudentJudges->isNotEmpty())
        <flux:callout icon="academic-cap" color="zinc">
            <flux:callout.heading>Judges scoring their own students</flux:callout.heading>
            <flux:callout.text>
                These judges have students they teach, or students from a school they teach at, in their Room. Each comparison is against the co-judges' totals for the same candidates.
            </flux:callout.text>
            <ul class="mt-2 space-y-1 text-sm text-zinc-700 dark:text-zinc-300">
                @foreach ($ownStudentJudges as $judge)
                    <li wire:key="own-{{ $judge['judge']->id }}">
                        <span class="font-medium">{{ $judge['name'] }}</span> ({{ $judge['roomName'] }}) —
                        {{ $judge['ownStudents']['ownTeacher'] }} taught by them, {{ $judge['ownStudents']['sameSchool'] }} same school;
                        {{ $leanText($judge['ownStudents']) }}
                    </li>
                @endforeach
            </ul>
        </flux:callout>
    @endif

    {{-- Daily activity --}}
    @if ($progress['daily'] !== [])
        <div>
            <flux:heading size="lg" class="mb-1">Daily activity</flux:heading>
            <flux:text size="sm" class="text-zinc-500 mb-3">Candidates scored per day, across all judges (Eastern time).</flux:text>

            <flux:card>
                <flux:chart :value="$progress['daily']" class="aspect-[3/1]">
                    <flux:chart.svg>
                        <flux:chart.bar field="saves" class="text-[#2a78d6] dark:text-[#3987e5]" radius="4" />
                        <flux:chart.axis axis="x" field="x">
                            <flux:chart.axis.line />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>
                        <flux:chart.axis axis="y" :format="['maximumFractionDigits' => 0]">
                            <flux:chart.axis.grid />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>
                        <flux:chart.cursor />
                    </flux:chart.svg>
                    <flux:chart.tooltip>
                        <flux:chart.tooltip.heading field="heading" />
                        <flux:chart.tooltip.value field="saves" label="Candidates scored" />
                    </flux:chart.tooltip>
                </flux:chart>
            </flux:card>
        </div>
    @endif

    {{-- Rooms --}}
    <div>
        <flux:heading size="lg" class="mb-1">Rooms</flux:heading>
        <flux:text size="sm" class="text-zinc-500 mb-3 max-w-3xl">
            A sitting is a stretch of scoring with no break longer than {{ intdiv(\App\Services\AdjudicationProgressService::SITTING_BREAK_SECONDS, 60) }} minutes.
            Pace is the median time between a judge's saves within a sitting.
            @if ($listeningTracked)
                Heard is how far into a candidate's recordings the judge had played, on average, when they first saved scores; it covers only saves made since playback tracking began.
            @endif
        </flux:text>

        @forelse ($progress['rooms'] as $room)
            <flux:card class="mb-4 space-y-4" wire:key="room-{{ $room['room']->id }}">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="md">{{ $room['room']->name }}</flux:heading>
                        @if ($room['flag'] !== null)
                            <flux:badge size="sm" :color="$roomFlag[$room['flag']][1]">{{ $roomFlag[$room['flag']][0] }}</flux:badge>
                        @endif
                    </div>
                    <flux:text size="sm" class="tabular-nums">
                        {{ $room['candidatesComplete'] }} of {{ $room['candidates'] }} candidates fully scored · {{ $room['percent'] }}%
                    </flux:text>
                </div>
                <x-adjudication-progress-bar :percent="$room['percent']" />
                @if ($room['flag'] === 'stalled')
                    <flux:text size="sm" class="text-red-600 dark:text-red-400">
                        None of this Room's judges has scored yet.
                    </flux:text>
                @elseif ($room['flag'] === 'uneven')
                    <flux:text size="sm" class="text-amber-600 dark:text-amber-400">
                        {{ $room['judgesNotStarted'] }} {{ \Illuminate\Support\Str::plural('judge', $room['judgesNotStarted']) }} {{ $room['judgesNotStarted'] === 1 ? "hasn't" : "haven't" }} started — no candidate in this Room can be fully scored until every judge has.
                    </flux:text>
                @endif

                {{-- Mobile: cards --}}
                <div class="space-y-2 md:hidden">
                    @foreach ($room['judges'] as $judge)
                        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3 space-y-1" wire:key="m-judge-{{ $judge['judge']->id }}">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-medium text-sm text-zinc-800 dark:text-white">{{ $judge['name'] }}</span>
                                <flux:badge size="sm" :color="$statusBadge[$judge['status']][1]">{{ $statusBadge[$judge['status']][0] }}</flux:badge>
                            </div>
                            <a href="mailto:{{ $judge['email'] }}" class="block text-xs font-normal text-blue-600 dark:text-blue-400 hover:underline select-all">{{ $judge['email'] }}</a>
                            <flux:text size="sm">{{ $judge['type'] }} · {{ $judge['completed'] }}/{{ $room['candidates'] }} ({{ $judge['percent'] }}%)@if ($judge['idle']) · <span class="text-amber-600 dark:text-amber-400">idle</span>@endif</flux:text>
                            @if ($judge['ownStudents']['count'] > 0)
                                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ $ownLabel($judge['ownStudents']) }} · {{ $leanText($judge['ownStudents']) }}</flux:text>
                            @endif
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                {{ $judge['sittings'] }} {{ \Illuminate\Support\Str::plural('sitting', $judge['sittings']) }} · pace {{ $pace($judge['paceSeconds']) }} · est. {{ $duration($judge['remainingMinutes']) }} left
                            </flux:text>
                            @if ($judge['listening']['heardPercent'] !== null)
                                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                    Heard {{ $judge['listening']['heardPercent'] }}% before scoring @if ($judge['listening']['short'] > 0) · <span class="text-amber-600 dark:text-amber-400">{{ $judge['listening']['short'] }} short</span>@endif
                                </flux:text>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- md+: table --}}
                <flux:table class="hidden md:table">
                    <flux:table.columns>
                        <flux:table.column>Judge</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column align="end">Scored</flux:table.column>
                        <flux:table.column class="w-40">Progress</flux:table.column>
                        <flux:table.column align="end">Sittings</flux:table.column>
                        <flux:table.column align="end">Pace</flux:table.column>
                        <flux:table.column align="end">Est. time left</flux:table.column>
                        @if ($listeningTracked)
                            <flux:table.column align="end">Heard</flux:table.column>
                        @endif
                        <flux:table.column>First / last activity</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($room['judges'] as $judge)
                            <flux:table.row :key="'judge-'.$judge['judge']->id">
                                <flux:table.cell class="font-medium">
                                    {{ $judge['name'] }}<a href="mailto:{{ $judge['email'] }}" class="block text-xs font-normal text-blue-600 dark:text-blue-400 hover:underline select-all">{{ $judge['email'] }}</a><span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $judge['type'] }}</span>
                                    @if ($judge['ownStudents']['count'] > 0)
                                        <flux:tooltip :content="$leanText($judge['ownStudents'])">
                                            <span class="block text-xs font-normal text-zinc-500 dark:text-zinc-400 underline decoration-dotted">{{ $ownLabel($judge['ownStudents']) }}</span>
                                        </flux:tooltip>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="$statusBadge[$judge['status']][1]">{{ $statusBadge[$judge['status']][0] }}</flux:badge>
                                    @if ($judge['idle'])
                                        <flux:badge size="sm" color="amber">Idle</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">
                                    {{ $judge['completed'] }}/{{ $room['candidates'] }}
                                    @if ($judge['partial'] > 0)
                                        <span class="block text-xs text-amber-600 dark:text-amber-400">+{{ $judge['partial'] }} partial</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell><x-adjudication-progress-bar :percent="$judge['percent']" /></flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $judge['sittings'] }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $pace($judge['paceSeconds']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">
                                    {{ $duration($judge['remainingMinutes']) }}
                                    @if ($judge['remainingMinutes'] !== null && $judge['paceIsEstimate'])
                                        <span class="block text-xs text-zinc-500 dark:text-zinc-400">typical pace</span>
                                    @endif
                                </flux:table.cell>
                                @if ($listeningTracked)
                                    <flux:table.cell align="end" class="tabular-nums">
                                        @if ($judge['listening']['heardPercent'] === null)
                                            —
                                        @else
                                            {{ $judge['listening']['heardPercent'] }}%
                                            @if ($judge['listening']['short'] > 0)
                                                <span class="block text-xs text-amber-600 dark:text-amber-400">{{ $judge['listening']['short'] }} short</span>
                                            @endif
                                        @endif
                                    </flux:table.cell>
                                @endif
                                <flux:table.cell class="text-sm">
                                    {{ $when($judge['firstActivity']) }}
                                    @if ($judge['lastActivity'] !== null)
                                        <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $when($judge['lastActivity']) }}</span>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @empty
            <flux:callout icon="building-office">
                <flux:callout.heading>No Rooms are set up for this Version yet</flux:callout.heading>
            </flux:callout>
        @endforelse
    </div>
</div>
