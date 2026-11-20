@php
    $signed = fn (int $n): string => $n > 0 ? '+'.number_format($n) : ($n < 0 ? "\u{2212}".number_format(abs($n)) : '0');
    $notBackHeading = $retention['final'] ? 'Not back' : 'Not back yet';
    $tiles = [
        'Schools' => [
            ['Last year', number_format($retention['schools']['prior'])],
            ['Retained', number_format($retention['schools']['retained']).' ('.$retention['schools']['retainedPercent'].'%)'],
            [$notBackHeading, number_format($retention['schools']['notBack'])],
            ['New', number_format($retention['schools']['new'])],
        ],
        'Students' => [
            ['Last year → this year', number_format($retention['students']['prior']).' → '.number_format($retention['students']['current'])],
            ['Retained schools', $signed($retention['students']['retainedChange'])],
            ['Schools '.strtolower($notBackHeading), $signed($retention['students']['notBackLost'])],
            ['New schools', $signed($retention['students']['newGained'])],
        ],
        'Teachers' => [
            ['With registered students', number_format($retention['teachers']['current'])],
            ['New to the event', number_format($retention['teachers']['newToEvent']).' ('.number_format($retention['teachers']['newToEventStudents']).' '.\Illuminate\Support\Str::plural('student', $retention['teachers']['newToEventStudents']).')'],
            ['New to TDR', number_format($retention['teachers']['newToTdr'])],
        ],
    ];
@endphp

<div class="mb-10">
    <flux:heading size="lg" class="mb-1">School retention</flux:heading>
    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 max-w-3xl mb-4">
        Compared with {{ $retention['prior']->name }}. A school counts once it has a registered student, so a school that's back under a new teacher is retained.
        @unless ($retention['final'])
            Until the Postmark Deadline, a school that isn't back may still register.
        @endunless
    </flux:text>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 mb-6">
        @foreach ($tiles as $heading => $rows)
            <flux:card class="space-y-3">
                <flux:heading size="sm">{{ $heading }}</flux:heading>
                <dl class="space-y-2">
                    @foreach ($rows as [$label, $value])
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                            <dd class="text-lg font-semibold tabular-nums text-zinc-800 dark:text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </flux:card>
        @endforeach
    </div>

    {{-- Schools not back --}}
    @if ($retention['notBack'] !== [])
        <flux:heading size="md" class="mb-1">{{ $notBackHeading }}</flux:heading>
        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 max-w-3xl mb-3">
            Schools with registered students last year and none this year. A school with no invited teacher can't come back until someone there is invited.
        </flux:text>

        {{-- Mobile: cards --}}
        <div class="space-y-3 md:hidden mb-6">
            @foreach ($retention['notBack'] as $school)
                <flux:card class="space-y-1" wire:key="m-notback-{{ $loop->index }}">
                    <div class="flex items-center justify-between gap-2">
                        <flux:heading size="sm">{{ $school['name'] }}</flux:heading>
                        <flux:text size="sm" class="tabular-nums">{{ $school['priorStudents'] }} last year</flux:text>
                    </div>
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">Last year: {{ implode(', ', $school['priorTeachers']) }}</flux:text>
                    @forelse ($school['invited'] as $teacher)
                        <flux:text size="sm">
                            {{ $teacher['name'] }} (<a href="mailto:{{ $teacher['email'] }}" class="text-blue-600 dark:text-blue-400 hover:underline">{{ $teacher['email'] }}</a>):
                            <span class="tabular-nums">{{ $teacher['eligible'] }} eligible, {{ $teacher['pending'] }} pending</span>
                        </flux:text>
                    @empty
                        <flux:badge size="sm" color="red">No invited teacher</flux:badge>
                    @endforelse
                </flux:card>
            @endforeach
        </div>

        {{-- md+: table --}}
        <flux:table class="hidden md:table mb-6">
            <flux:table.columns>
                <flux:table.column>School</flux:table.column>
                <flux:table.column align="end">Last year</flux:table.column>
                <flux:table.column>Last year's teacher</flux:table.column>
                <flux:table.column>Invited now</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($retention['notBack'] as $school)
                    <flux:table.row :key="'notback-'.$loop->index">
                        <flux:table.cell class="font-medium">{{ $school['name'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $school['priorStudents'] }}</flux:table.cell>
                        <flux:table.cell class="whitespace-normal">{{ implode(', ', $school['priorTeachers']) }}</flux:table.cell>
                        <flux:table.cell class="whitespace-normal text-sm">
                            @forelse ($school['invited'] as $teacher)
                                <span class="block">
                                    {{ $teacher['name'] }} (<a href="mailto:{{ $teacher['email'] }}" class="text-blue-600 dark:text-blue-400 hover:underline">{{ $teacher['email'] }}</a>):
                                    <span class="tabular-nums">{{ $teacher['eligible'] }} eligible, {{ $teacher['pending'] }} pending</span>
                                </span>
                            @empty
                                <flux:badge size="sm" color="red">No invited teacher</flux:badge>
                            @endforelse
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    {{-- Retained schools with fewer students --}}
    @if ($retention['fewer'] !== [])
        <flux:heading size="md" class="mb-1">Retained schools with fewer students</flux:heading>
        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 max-w-3xl mb-3">
            Largest drop first. Not registered counts this year's eligible and pending students at the school.
        </flux:text>

        {{-- Mobile: cards --}}
        <div class="space-y-3 md:hidden mb-6">
            @foreach ($retention['fewer'] as $school)
                <flux:card class="space-y-1" wire:key="m-fewer-{{ $loop->index }}">
                    <div class="flex items-center justify-between gap-2">
                        <flux:heading size="sm">{{ $school['name'] }}</flux:heading>
                        <flux:badge size="sm" color="amber">{{ $signed($school['change']) }}</flux:badge>
                    </div>
                    <flux:text size="sm" class="tabular-nums">{{ $school['priorStudents'] }} → {{ $school['currentStudents'] }} · {{ $school['unregistered'] }} not registered</flux:text>
                </flux:card>
            @endforeach
        </div>

        {{-- md+: table --}}
        <flux:table class="hidden md:table mb-6">
            <flux:table.columns>
                <flux:table.column>School</flux:table.column>
                <flux:table.column align="end">Last year</flux:table.column>
                <flux:table.column align="end">This year</flux:table.column>
                <flux:table.column align="end">Change</flux:table.column>
                <flux:table.column align="end">Not registered</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($retention['fewer'] as $school)
                    <flux:table.row :key="'fewer-'.$loop->index">
                        <flux:table.cell class="font-medium">{{ $school['name'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $school['priorStudents'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $school['currentStudents'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums font-medium text-amber-600 dark:text-amber-400">{{ $signed($school['change']) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $school['unregistered'] }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    {{-- Retained under a different teacher --}}
    @if ($retention['teacherChanged'] !== [])
        <flux:heading size="md" class="mb-1">Retained with a teacher change</flux:heading>
        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 max-w-3xl mb-3">
            Schools that are back with a different set of teachers than last year.
        </flux:text>

        {{-- Mobile: cards --}}
        <div class="space-y-3 md:hidden">
            @foreach ($retention['teacherChanged'] as $school)
                <flux:card class="space-y-1" wire:key="m-changed-{{ $loop->index }}">
                    <div class="flex items-center justify-between gap-2">
                        <flux:heading size="sm">{{ $school['name'] }}</flux:heading>
                        <flux:text size="sm" class="tabular-nums">{{ $school['priorStudents'] }} → {{ $school['currentStudents'] }}</flux:text>
                    </div>
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">Was: {{ implode(', ', $school['was']) }}</flux:text>
                    <flux:text size="sm">Now: {{ implode(', ', $school['now']) }}</flux:text>
                </flux:card>
            @endforeach
        </div>

        {{-- md+: table --}}
        <flux:table class="hidden md:table">
            <flux:table.columns>
                <flux:table.column>School</flux:table.column>
                <flux:table.column align="end">Last year → this year</flux:table.column>
                <flux:table.column>Was</flux:table.column>
                <flux:table.column>Now</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($retention['teacherChanged'] as $school)
                    <flux:table.row :key="'changed-'.$loop->index">
                        <flux:table.cell class="font-medium">{{ $school['name'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $school['priorStudents'] }} → {{ $school['currentStudents'] }}</flux:table.cell>
                        <flux:table.cell class="whitespace-normal">{{ implode(', ', $school['was']) }}</flux:table.cell>
                        <flux:table.cell class="whitespace-normal">{{ implode(', ', $school['now']) }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
