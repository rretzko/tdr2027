@php
    $groups = [
        'Invitations' => [
            ['Invited Teachers', number_format($metrics->invitedTeachers)],
            ['Invited Schools', number_format($metrics->invitedSchools)],
            ['Eligible Students', number_format($metrics->eligibleStudents)],
        ],
        'Engaged' => [
            ['Obligated Teachers', number_format($metrics->obligatedTeachers)],
            ['Obligated Schools', number_format($metrics->obligatedSchools)],
            ['Engaged Students', number_format($metrics->engagedStudents)],
        ],
        'Registered' => [
            ['Registered Teachers', number_format($metrics->registeredTeachers)],
            ['Registered Schools', number_format($metrics->registeredSchools)],
            ['Registered Students', number_format($metrics->registeredStudents)],
        ],
        'Registration Fees' => [
            ['Due', '$'.number_format($metrics->registrationFeesDueInDollars(), 2)],
            ['Paid', '$'.number_format($metrics->registrationFeesPaidInDollars(), 2)],
            ['Outstanding', '$'.number_format($metrics->registrationFeesOutstandingInDollars(), 2)],
        ],
    ];
    $firstSnapshotOn = $rows === [] ? null : \Illuminate\Support\Carbon::parse($rows[0]['date']);
@endphp

<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.edit', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Scorecard</span>
    </div>

    <div class="mb-6">
        <flux:heading size="xl">Scorecard</flux:heading>
        <flux:text size="sm" class="text-zinc-500 max-w-3xl">
            The numbers from the Monday Morning Scorecard email. The tiles below are current as of right now; the trends are captured every Monday morning.
        </flux:text>
    </div>

    {{-- Right now --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-10">
        @foreach ($groups as $heading => $tiles)
            <flux:card class="space-y-3">
                <flux:heading size="sm">{{ $heading }}</flux:heading>
                <dl class="space-y-2">
                    @foreach ($tiles as [$label, $value])
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                            <dd class="text-lg font-semibold tabular-nums text-zinc-800 dark:text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </flux:card>
        @endforeach
    </div>

    {{-- Weekly trends --}}
    <flux:heading size="lg" class="mb-1">Weekly trends</flux:heading>

    @if (count($rows) < 2)
        <flux:callout icon="chart-bar" class="mt-3">
            <flux:callout.heading>Trends need at least two Mondays</flux:callout.heading>
            <flux:callout.text>
                @if ($firstSnapshotOn === null)
                    Snapshots are captured each Monday morning while {{ $version->name }} is Active. The first one hasn't been taken yet.
                @else
                    The first snapshot was taken {{ $firstSnapshotOn->format('l, F j') }}. The charts appear once the next Monday's snapshot is added.
                @endif
            </flux:callout.text>
        </flux:callout>
    @else
        <flux:text size="sm" class="text-zinc-500 mb-4">
            Since {{ $firstSnapshotOn->format('F j, Y') }}. Hover a chart to see each Monday's values.
        </flux:text>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-scorecard-trend-chart title="Teachers" :rows="$rows" :series="[
                ['field' => 'invited_teachers', 'label' => 'Invited', 'slot' => 1],
                ['field' => 'obligated_teachers', 'label' => 'Obligated', 'slot' => 2],
                ['field' => 'registered_teachers', 'label' => 'Registered', 'slot' => 3],
            ]" />
            <x-scorecard-trend-chart title="Schools" :rows="$rows" :series="[
                ['field' => 'invited_schools', 'label' => 'Invited', 'slot' => 1],
                ['field' => 'obligated_schools', 'label' => 'Obligated', 'slot' => 2],
                ['field' => 'registered_schools', 'label' => 'Registered', 'slot' => 3],
            ]" />
            <x-scorecard-trend-chart title="Students" :rows="$rows" :series="[
                ['field' => 'eligible_students', 'label' => 'Eligible', 'slot' => 1],
                ['field' => 'engaged_students', 'label' => 'Engaged', 'slot' => 2],
                ['field' => 'registered_students', 'label' => 'Registered', 'slot' => 3],
            ]" />
            <x-scorecard-trend-chart title="Registration Fees" :currency="true" :rows="$rows" :series="[
                ['field' => 'fees_due', 'label' => 'Due', 'slot' => 1],
                ['field' => 'fees_paid', 'label' => 'Paid', 'slot' => 3],
            ]" />
        </div>
    @endif

    {{-- Snapshot history (table view of the charted data) --}}
    @if ($rows !== [])
        <flux:heading size="lg" class="mt-10 mb-3">Snapshot history</flux:heading>

        {{-- Mobile: cards --}}
        <div class="space-y-3 md:hidden">
            @foreach (array_reverse($rows) as $row)
                <flux:card class="space-y-2">
                    <flux:heading size="sm">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('M j, Y') }}</flux:heading>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <dt class="text-zinc-500 dark:text-zinc-400">Teachers (inv / obl / reg)</dt>
                        <dd class="text-right tabular-nums">{{ $row['invited_teachers'] }} / {{ $row['obligated_teachers'] }} / {{ $row['registered_teachers'] }}</dd>
                        <dt class="text-zinc-500 dark:text-zinc-400">Schools (inv / obl / reg)</dt>
                        <dd class="text-right tabular-nums">{{ $row['invited_schools'] }} / {{ $row['obligated_schools'] }} / {{ $row['registered_schools'] }}</dd>
                        <dt class="text-zinc-500 dark:text-zinc-400">Students (elig / eng / reg)</dt>
                        <dd class="text-right tabular-nums">{{ $row['eligible_students'] }} / {{ $row['engaged_students'] }} / {{ $row['registered_students'] }}</dd>
                        <dt class="text-zinc-500 dark:text-zinc-400">Fees paid / due</dt>
                        <dd class="text-right tabular-nums">${{ number_format($row['fees_paid'], 2) }} / ${{ number_format($row['fees_due'], 2) }}</dd>
                    </dl>
                </flux:card>
            @endforeach
        </div>

        {{-- md+: full table --}}
        <flux:table class="hidden md:table">
            <flux:table.columns>
                <flux:table.column>Monday</flux:table.column>
                <flux:table.column align="end">Invited Teachers</flux:table.column>
                <flux:table.column align="end">Obligated Teachers</flux:table.column>
                <flux:table.column align="end">Registered Teachers</flux:table.column>
                <flux:table.column align="end">Invited Schools</flux:table.column>
                <flux:table.column align="end">Obligated Schools</flux:table.column>
                <flux:table.column align="end">Registered Schools</flux:table.column>
                <flux:table.column align="end">Eligible Students</flux:table.column>
                <flux:table.column align="end">Engaged Students</flux:table.column>
                <flux:table.column align="end">Registered Students</flux:table.column>
                <flux:table.column align="end">Fees Due</flux:table.column>
                <flux:table.column align="end">Fees Paid</flux:table.column>
                <flux:table.column align="end">Outstanding</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach (array_reverse($rows) as $row)
                    <flux:table.row :key="$row['date']">
                        <flux:table.cell class="font-medium">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('M j, Y') }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $row['invited_teachers'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $row['obligated_teachers'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $row['registered_teachers'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $row['invited_schools'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $row['obligated_schools'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $row['registered_schools'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $row['eligible_students'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $row['engaged_students'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $row['registered_students'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">${{ number_format($row['fees_due'], 2) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">${{ number_format($row['fees_paid'], 2) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">${{ number_format($row['fees_outstanding'], 2) }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
