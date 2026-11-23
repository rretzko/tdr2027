<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; }
        h2 { font-size: 15px; margin: 0 0 4px; border-bottom: 2px solid #2a78d6; padding-bottom: 3px; }
        h3 { font-size: 12px; margin: 16px 0 6px; }
        .note { color: #555; font-size: 9px; margin: 0 0 8px; }
        .tiles td { border: none; padding: 4px 10px 4px 0; width: 25%; }
        .tile-label { color: #666; font-size: 9px; }
        .tile-value { font-size: 15px; font-weight: bold; }
        td.num, th.num { text-align: right; }
        .legend span { display: inline-block; margin-right: 12px; }
        .swatch { display: inline-block; width: 9px; height: 9px; margin-right: 3px; }
        .chart { width: 100%; }
        .pies td { border: none; text-align: center; vertical-align: top; }
        .muted { color: #777; }
        .avoid-break { page-break-inside: avoid; }
        table.compact th, table.compact td { padding: 3px 6px; }
        dl.appendix { margin: 0 0 6px; }
        dl.appendix dt { font-weight: bold; margin-top: 5px; }
        dl.appendix dd { margin: 1px 0 0 0; line-height: 1.3; }
    </style>
</head>
<body>
@php
    $date = fn (?string $iso, string $format = 'M j, Y'): string => $iso === null ? '—' : \Illuminate\Support\Carbon::parse($iso)->forDisplay()->format($format);
    $money = fn (int $cents): string => '$'.number_format($cents / 100, 2);
    $signed = fn (int|float|null $value, int $decimals = 2): string => $value === null ? '—' : ($value > 0 ? '+' : '').number_format($value, $decimals);
    $label = fn (string $enum, string $value): string => $enum::tryFrom($value)?->label() ?? \Illuminate\Support\Str::headline($value);
    $counts = $registration['counts'];
@endphp

<h1>Analytics Report</h1>
<p class="subheading">
    {{ $version->event->name }} &mdash; {{ $version->name }}<br>
    As of Close Audition, {{ $date($snapshot->captured_at->toIso8601String(), 'M j, Y \a\t g:i a') }}. Figures are frozen at Close and don't change if data is edited later.
</p>

{{-- ============================== Registration ============================== --}}
<h2>1. Registration</h2>
<p class="note">Registered counts include every student who registered, whatever their audition outcome.</p>

<table>
    <thead>
        <tr><th></th><th class="num">Invited</th><th class="num">Obligated</th><th class="num">Registered</th></tr>
    </thead>
    <tbody>
        <tr><td>Teachers</td><td class="num">{{ $counts['invitedTeachers'] }}</td><td class="num">{{ $counts['obligatedTeachers'] }}</td><td class="num">{{ $counts['registeredTeachers'] }}</td></tr>
        <tr><td>Schools</td><td class="num">{{ $counts['invitedSchools'] }}</td><td class="num">{{ $counts['obligatedSchools'] }}</td><td class="num">{{ $counts['registeredSchools'] }}</td></tr>
        <tr><td>Students</td><td class="num">{{ $counts['eligibleStudents'] }} eligible</td><td class="num">{{ $counts['engagedStudents'] }} engaged</td><td class="num">{{ $counts['registeredStudents'] }}</td></tr>
    </tbody>
</table>

<table class="tiles">
    <tr>
        <td><div class="tile-label">Registration fees due</div><div class="tile-value">{{ $money($registration['fees']['dueCents']) }}</div></td>
        <td><div class="tile-label">Paid</div><div class="tile-value">{{ $money($registration['fees']['paidCents']) }}</div></td>
        <td><div class="tile-label">Outstanding</div><div class="tile-value">{{ $money($registration['fees']['outstandingCents']) }}</div></td>
        <td><div class="tile-label">Waived</div><div class="tile-value">{{ $money($registration['fees']['waivedCents']) }}</div></td>
    </tr>
</table>

@if ($charts['registrationTiming'] !== null)
    <div class="avoid-break">
        <h3>When students were registered</h3>
        <p class="note">
            Registrations per day.
            @if ($registration['timing']['cutoffName'] ?? null)
                Day 0 is {{ $registration['timing']['cutoffName'] }} ({{ $date($registration['timing']['cutoff']) }}).
            @endif
        </p>
        <p class="legend">
            @foreach ($registration['timing']['phases'] as $phase)
                <span><span class="swatch" style="background: {{ ['early' => '#2a78d6', 'middle' => '#1baf7a', 'late' => '#eb6834'][$phase['field']] }}"></span>{{ $phase['label'] }}: {{ $phase['count'] }} ({{ $phase['percent'] }}%)</span>
            @endforeach
        </p>
        <img class="chart" src="{{ $charts['registrationTiming'] }}">
    </div>
@endif

@if (($registration['retention'] ?? null) !== null)
    @php $retention = $registration['retention']; @endphp
    <div class="avoid-break">
        <h3>Compared with {{ $retention['prior']['name'] }}</h3>
        <table>
            <tbody>
                <tr><td>Schools</td><td>{{ $retention['schools']['prior'] }} last time; {{ $retention['schools']['retained'] }} came back ({{ $retention['schools']['retainedPercent'] }}%), {{ $retention['schools']['notBack'] }} didn't, {{ $retention['schools']['new'] }} new</td></tr>
                <tr><td>Students</td><td>{{ $retention['students']['prior'] }} last time, {{ $retention['students']['current'] }} this time ({{ $signed($retention['students']['current'] - $retention['students']['prior'], 0) }})</td></tr>
                <tr><td>Teachers</td><td>{{ $retention['teachers']['current'] }} registering; {{ $retention['teachers']['newToEvent'] }} new to this event ({{ $retention['teachers']['newToEventStudents'] }} students), {{ $retention['teachers']['newToTdr'] }} new to TheDirectorsRoom.com</td></tr>
            </tbody>
        </table>
    </div>
@endif

@if ($registration['uploads'] !== [])
    <h3>Who uploaded recordings</h3>
    <table>
        <thead><tr><th>Recording</th><th class="num">Student</th><th class="num">Teacher</th><th class="num">Someone else</th></tr></thead>
        <tbody>
            @foreach ($registration['uploads'] as $upload)
                @php $all = max(1, $upload['student'] + $upload['teacher'] + $upload['other']); @endphp
                <tr>
                    <td>{{ ucfirst($upload['type']) }}</td>
                    <td class="num">{{ $upload['student'] }} ({{ round($upload['student'] / $all * 100) }}%)</td>
                    <td class="num">{{ $upload['teacher'] }} ({{ round($upload['teacher'] / $all * 100) }}%)</td>
                    <td class="num">{{ $upload['other'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if ($registration['strandedPending']['count'] > 0)
    <div class="avoid-break">
    <h3>Still pending at Close</h3>
    <p class="note">{{ $registration['strandedPending']['count'] }} candidates never completed registration, so they couldn't be adjudicated.</p>
    {{-- Every teacher, in two columns reading down (most pending first) to keep the list compact. --}}
    @php
        $stranded = collect($registration['strandedPending']['teachers'])->values();
        $half = (int) ceil($stranded->count() / 2);
        $left = $stranded->slice(0, $half)->values();
        $right = $stranded->slice($half)->values();
    @endphp
    <table class="compact">
        <thead>
            <tr><th>Teacher</th><th class="num">Pending</th><th>Teacher</th><th class="num">Pending</th></tr>
        </thead>
        <tbody>
            @for ($i = 0; $i < $half; $i++)
                <tr>
                    <td>{{ $left[$i]['name'] }}</td><td class="num">{{ $left[$i]['count'] }}</td>
                    <td>{{ $right[$i]['name'] ?? '' }}</td><td class="num">{{ $right[$i]['count'] ?? '' }}</td>
                </tr>
            @endfor
        </tbody>
    </table>
    </div>
@endif

{{-- ============================== Adjudication ============================== --}}
<div class="page-break"></div>
@php $totals = $adjudication['totals']; @endphp
<h2>2. Adjudication</h2>
<p class="note">Window: {{ $date($adjudication['window']['start'] ?? null) }} to {{ $date($adjudication['window']['end'] ?? null) }}.</p>

<table class="tiles">
    <tr>
        <td><div class="tile-label">Scorings completed</div><div class="tile-value">{{ number_format($totals['completed']) }} / {{ number_format($totals['expected']) }}</div></td>
        <td><div class="tile-label">Candidates fully scored</div><div class="tile-value">{{ $totals['candidatesComplete'] }} / {{ $totals['candidates'] }}</div></td>
        <td><div class="tile-label">Judges finished</div><div class="tile-value">{{ $totals['judgesComplete'] }} / {{ $totals['judges'] }}</div></td>
        <td><div class="tile-label">Median time per candidate</div><div class="tile-value">{{ $totals['paceSeconds'] === null ? '—' : $totals['paceSeconds'].'s' }}</div></td>
    </tr>
</table>

@if ($charts['dailySaves'] !== null)
    <div class="avoid-break">
        <h3>Scoring per day</h3>
        <img class="chart" src="{{ $charts['dailySaves'] }}">
    </div>
@endif

<h3>Rooms</h3>
<table>
    <thead><tr><th>Room</th><th class="num">Candidates</th><th class="num">Fully scored</th><th class="num">Judges</th></tr></thead>
    <tbody>
        @foreach ($adjudication['rooms'] as $room)
            <tr>
                <td>{{ $room['room']['name'] }}</td>
                <td class="num">{{ $room['candidates'] }}</td>
                <td class="num">{{ $room['candidatesComplete'] }} ({{ $room['percent'] }}%)</td>
                <td class="num">{{ count(array_filter($room['judges'], fn (array $judge): bool => $judge['scoring'])) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@if (($adjudication['agreement']['candidates'] ?? 0) > 0)
    <p>
        <strong>Scales vs Solo:</strong> {{ $adjudication['agreement']['candidates'] }} candidates were heard in more than one Room.
        Their standings agreed at r = {{ $adjudication['agreement']['r'] ?? '—' }};
        {{ count($adjudication['agreement']['gaps']) }} differed by 50 or more percentile points between Rooms.
    </p>
@endif

@if (($adjudication['judgeMeasures'] ?? []) !== [])
    <h3>How the judges scored</h3>
    <p class="note">
        Each judge is compared with their co-judges on the same candidates, full sheets only; this is information for planning, not a verdict on any judge.
        Lean: average total against co-judges, positive when more favorable. Top two: share of scores on the judge's two most-used values.
        Flat: share of sheets with one score throughout. Drift: change in lean from the first third of the roster to the last.
        Revised: sheets edited 10+ minutes after the first save.
    </p>
    @foreach ($adjudication['judgeMeasures'] as $room)
        <div class="avoid-break">
            <p style="margin: 10px 0 4px;">
                <strong>{{ $room['room']['name'] }}</strong>
                <span class="muted">&mdash; tolerance {{ $room['tolerance']['value'] ?? 'none' }}, largest gap {{ $room['tolerance']['largestGap'] ?? '—' }}, {{ $room['tolerance']['outside'] }} outside{{ $room['tolerance']['loose'] ? ' · loose: '.$room['tolerance']['value'].' of a '.$room['tolerance']['judgeTotalRange'].'-point range' : '' }}</span>
            </p>
            <table>
                <thead>
                    <tr><th>Judge</th><th class="num">Sheets</th><th class="num">Lean</th><th class="num">Drift</th><th class="num">Values used</th><th class="num">Top two</th><th class="num">Flat</th><th class="num">Revised</th></tr>
                </thead>
                <tbody>
                    @foreach ($room['judges'] as $judge)
                        <tr>
                            <td>{{ $judge['name'] }} <span class="muted">({{ $judge['type'] }})</span></td>
                            <td class="num">{{ $judge['sheets'] }}</td>
                            <td class="num">{{ $signed($judge['calibration']['lean']) }}</td>
                            <td class="num">{{ $signed($judge['drift']['change']) }}</td>
                            <td class="num">{{ $judge['scaleUse']['valuesUsed'] }} / {{ $judge['scaleUse']['valuesPossible'] }}</td>
                            <td class="num">{{ $judge['scaleUse']['topTwoShare'] === null ? '—' : $judge['scaleUse']['topTwoShare'].'%' }}</td>
                            <td class="num">{{ $judge['scaleUse']['sameEveryFactor'] === null ? '—' : $judge['scaleUse']['sameEveryFactor'].'%' }}</td>
                            <td class="num">{{ $judge['review']['revised'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="note">
                Agreement:
                @foreach ($room['pairs'] as $pair)
                    {{ $pair['judges'][0] }} &amp; {{ $pair['judges'][1] }} r {{ $pair['r'] ?? '—' }}, average gap {{ $pair['averageGap'] ?? '—' }}{{ $loop->last ? '.' : ';' }}
                @endforeach
            </p>
        </div>
    @endforeach
@endif

@php $survey = $adjudication['survey'] ?? null; @endphp
@if ($survey !== null && ($survey['shown'] ?? 0) > 0)
    <div class="avoid-break">
        <h3>Judge check-ins</h3>
        <p class="note">Anonymous and in aggregate only. {{ $survey['answered'] }} of {{ $survey['shown'] }} check-ins answered{{ $survey['responseRate'] !== null ? ' ('.$survey['responseRate'].'%)' : '' }} by {{ $survey['respondents'] }} judges.</p>
        @if ($survey['visible'])
            <table>
                <tbody>
                    @if ($survey['overall']['average'] !== null)
                        <tr><td>Overall rating</td><td>{{ number_format($survey['overall']['average'], 1) }} of 5 ({{ $survey['overall']['respondents'] }} judges)</td></tr>
                    @endif
                    <tr><td>Would judge again</td><td>{{ collect($survey['judgeAgain']['counts'])->map(fn (int $n, string $key): string => $label(\App\Enums\JudgeSurvey\JudgeAgain::class, $key).' '.$n)->implode(', ') }}</td></tr>
                    @foreach ($survey['checkpoints'] as $checkpoint)
                        @if ($checkpoint['focusAnswers'] > 0)
                            <tr>
                                <td>Focus at {{ $label(\App\Enums\JudgeSurvey\JudgeCheckpoint::class, $checkpoint['checkpoint']) }}</td>
                                <td>{{ collect($checkpoint['focus'])->filter()->map(fn (int $n, string $key): string => $label(\App\Enums\JudgeSurvey\JudgeFocus::class, $key).' '.$n)->implode(', ') }}</td>
                            </tr>
                        @endif
                    @endforeach
                    <tr><td>Listening on</td><td>{{ collect($survey['listening']['counts'])->filter()->map(fn (int $n, string $key): string => $label(\App\Enums\JudgeSurvey\ListeningDevice::class, $key).' '.$n)->implode(', ') ?: '—' }}</td></tr>
                    <tr><td>Device</td><td>{{ collect($survey['devices']['counts'])->filter()->map(fn (int $n, string $key): string => $label(\App\Enums\JudgeSurvey\JudgeDeviceType::class, $key).' '.$n)->implode(', ') ?: '—' }}</td></tr>
                    <tr><td>Obstacles</td><td>{{ collect($survey['obstacles']['counts'])->filter()->map(fn (int $n, string $key): string => $label(\App\Enums\JudgeSurvey\JudgeObstacle::class, $key).' '.$n)->implode(', ') ?: '—' }}</td></tr>
                </tbody>
            </table>
            @if ($survey['comments'] !== [])
                <p><strong>Comments</strong></p>
                @foreach ($survey['comments'] as $comment)
                    <p class="note">&ldquo;{{ $comment['text'] }}&rdquo;</p>
                @endforeach
            @endif
        @else
            <p class="note">Too few judges answered to show a breakdown without identifying them.</p>
        @endif
    </div>
@endif

{{-- ============================== Selection ============================== --}}
<div class="page-break"></div>
<h2>3. Selection</h2>

@if ($analysis === null)
    <p class="empty">No candidates were accepted or not accepted.</p>
@else
    @php $outcomes = $analysis['outcomes']; @endphp
    <p class="note">
        @if ($analysis['strategyLabel'] !== null)
            Cut-off strategy: {{ $analysis['strategyLabel'] }}.
        @endif
        {{ $analysis['higherIsBetter'] ? 'Higher' : 'Lower' }} totals are better.
    </p>

    <table class="tiles">
        <tr>
            <td><div class="tile-label">Decided</div><div class="tile-value">{{ $outcomes['decided'] }}</div></td>
            <td><div class="tile-label">Accepted</div><div class="tile-value">{{ $outcomes['accepted'] }} ({{ $outcomes['acceptRate'] }}%)</div></td>
            <td><div class="tile-label">Not accepted</div><div class="tile-value">{{ $outcomes['notAccepted'] }}</div></td>
            <td><div class="tile-label">No show / incomplete</div><div class="tile-value">{{ $outcomes['noShow'] }} / {{ $outcomes['incomplete'] }}</div></td>
        </tr>
    </table>

    <h3>Accepted by Ensemble</h3>
    <table>
        <thead><tr><th>Ensemble</th><th class="num">Accepted</th><th class="num">Last season</th></tr></thead>
        <tbody>
            @foreach ($analysis['ensembles'] as $ensemble)
                <tr>
                    <td>{{ $ensemble['name'] }}</td>
                    <td class="num">{{ $ensemble['accepted'] }}</td>
                    <td class="num">{{ $ensemble['prior'] === null ? '—' : $ensemble['prior'].' ('.$signed($ensemble['accepted'] - $ensemble['prior'], 0).')' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($charts['pies'] !== [])
        <table class="pies avoid-break">
            <tr>
                @foreach ($charts['pies'] as $pie)
                    <td>
                        <strong>{{ $pie['name'] }}</strong><br>
                        <span class="muted">{{ $pie['total'] }} accepted</span><br>
                        <img src="{{ $pie['image'] }}" style="width: 150px; height: 150px;"><br>
                        <span class="muted">{{ collect($pie['slices'])->map(fn (array $slice): string => $slice['label'].' '.$slice['count'])->implode(' · ') }}</span>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    @if ($analysis['lines'] !== [])
        <h3>How close the cut-offs were</h3>
        <p class="note">Near: candidates within 3% of the line's score on each side. Closest miss: how far the best candidate not accepted was from the line. * no stored cut-off; the line is the worst accepted total.</p>
        <table>
            <thead>
                <tr><th>Voice part</th><th>Ensemble</th><th class="num">Line</th><th class="num">Accepted</th><th class="num">Closest miss</th><th class="num">Near: in / out</th><th class="num">Medians in / out</th></tr>
            </thead>
            <tbody>
                @foreach ($analysis['lines'] as $line)
                    <tr>
                        <td>{{ $line['voicePart']['abbr'] }}</td>
                        <td>{{ $line['ensemble']['name'] ?? 'All' }}</td>
                        <td class="num">{{ $line['line'] }}{{ $line['lineSource'] === 'lowest_accepted' ? '*' : '' }}</td>
                        <td class="num">{{ $line['accepted'] }} / {{ $line['pool'] }} ({{ $line['acceptRate'] }}%)</td>
                        <td class="num">{{ $line['closestMiss'] ?? '—' }}</td>
                        <td class="num">{{ $line['nearAccepted'] }} / {{ $line['nearMissed'] }}</td>
                        <td class="num">{{ $line['acceptedMedian'] ?? '—' }} / {{ $line['missedMedian'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($charts['ranges'] !== null)
            <div class="avoid-break">
                <h3>Score distribution</h3>
                <p class="legend">
                    <span><span class="swatch" style="background: #2a78d6"></span>Accepted (lowest to highest total)</span>
                    <span><span class="swatch" style="background: #eb6834"></span>Not accepted</span>
                    <span><span class="swatch" style="background: #1a1a1a; width: 2px;"></span>Cut-off</span>
                </p>
                <img class="chart" src="{{ $charts['ranges'] }}">
            </div>
        @endif
    @else
        <p class="note">No scores were recorded for this Version's decisions, so there's no cut-off analysis.</p>
    @endif
@endif

@include('pdf.partials.analytics-report-appendix')
</body>
</html>
