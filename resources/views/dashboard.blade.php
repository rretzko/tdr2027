<x-layouts.app>
    @php $teacher = auth()->user()->teacher; @endphp

    <div class="flex flex-col gap-6">
        <flux:heading size="xl">Dashboard</flux:heading>

        @if ($teacher)
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-4">
            <a href="{{ route('schools.index') }}" wire:navigate class="block h-full transition hover:shadow-md">
                <flux:card class="flex h-full flex-col gap-4 border-l-4 border-l-blue-500 sm:border-2 sm:border-blue-500">
                    <flux:heading size="lg">Schools</flux:heading>

                    @php
                        // A single callback returning a [rank, name] tuple — not Collection::sortBy()'s
                        // multi-criteria array form, whose comparator callbacks need two args (a, b),
                        // not one. PHP compares same-shaped arrays element-by-element, so this sorts
                        // by status rank first and then alphabetically within each rank.
                        $sortedSchools = $teacher->schools->sortBy(
                            fn ($school) => [$school->pivot->statusSortRank(), $school->name]
                        );
                    @endphp

                    @foreach ($sortedSchools as $school)
                        <div class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="w-full">
                                <flux:text class="font-medium">{{ $school->name }}</flux:text>
                            </div>

                            <div class="flex flex-row items-center gap-2 sm:flex-col sm:items-start">
                                @if ($school->pivot->role)
                                    <flux:badge color="zinc" size="sm">{{ $school->pivot->role->label() }}</flux:badge>
                                @endif

                                <flux:badge color="{{ $school->pivot->is_active ? 'green' : 'zinc' }}" size="sm">
                                    {{ $school->pivot->is_active ? 'Active' : 'Inactive' }}
                                </flux:badge>

                                <flux:badge color="{{ $school->pivot->verified_at !== null ? 'green' : 'amber' }}" size="sm">
                                    {{ $school->pivot->verified_at !== null ? 'Email verified' : 'Email pending' }}
                                </flux:badge>
                            </div>
                        </div>
                    @endforeach
                </flux:card>
            </a>

            <a href="{{ route('students.index') }}" wire:navigate class="block h-full transition hover:shadow-md">
                <flux:card class="flex h-full flex-col gap-4 border-l-4 border-l-violet-500 sm:border-2 sm:border-violet-500">
                    <flux:heading size="lg">Students</flux:heading>

                    @php $rosterSummary = \App\Support\TeacherRosterSummary::forTeacher($teacher); @endphp

                    @foreach ($rosterSummary as $summary)
                        <div class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="w-full">
                                <flux:text class="font-medium">{{ $summary['school']->name }}</flux:text>
                            </div>

                            <flux:badge color="zinc" size="sm" class="self-start">{{ $summary['total'] }} {{ \Illuminate\Support\Str::plural('student', $summary['total']) }}</flux:badge>

                            @if ($summary['byGrade'] !== [])
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($summary['byGrade'] as $grade => $count)
                                        <flux:badge size="sm">Grade {{ $grade }}: {{ $count }}</flux:badge>
                                    @endforeach
                                </div>
                            @else
                                <flux:text size="sm" class="text-zinc-500">No students yet.</flux:text>
                            @endif
                        </div>
                    @endforeach
                </flux:card>
            </a>

            <a href="{{ route('organizations.index') }}" wire:navigate class="block h-full transition hover:shadow-md">
                <flux:card class="flex h-full flex-col gap-4 border-l-4 border-l-orange-500 sm:border-2 sm:border-orange-500">
                    <flux:heading size="lg">Organizations</flux:heading>

                    @php $organizations = $teacher->teacherSupervisors()->with('organization')->get()->pluck('organization'); @endphp

                    <flux:badge color="zinc" size="sm">
                        {{ $organizations->count() }} {{ \Illuminate\Support\Str::plural('organization', $organizations->count()) }}
                    </flux:badge>

                    @if ($organizations->isNotEmpty())
                        <div class="flex flex-wrap gap-2">
                            @foreach ($organizations as $organization)
                                <flux:badge size="sm">{{ $organization->abbr ?: $organization->name }}</flux:badge>
                            @endforeach
                        </div>
                    @else
                        <flux:text size="sm" class="text-zinc-500">No organizations linked yet.</flux:text>
                    @endif
                </flux:card>
            </a>

            <a href="{{ route('events.index') }}" wire:navigate class="block h-full transition hover:shadow-md">
                <flux:card class="flex h-full flex-col gap-4 border-l-4 border-l-teal-500 sm:border-2 sm:border-teal-500">
                    <flux:heading size="lg">Events</flux:heading>

                    @php
                        $organizationIds = $teacher->teacherSupervisors()->pluck('organization_id');
                        $openEvents = \App\Models\Event::where('status', 'active')->whereIn('organization_id', $organizationIds)->get();
                    @endphp

                    @if ($openEvents->isNotEmpty())
                        <div class="flex flex-col gap-2">
                            @foreach ($openEvents as $event)
                                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                    <flux:text class="font-medium">{{ $event->name }}</flux:text>
                                    <flux:text size="sm" class="text-zinc-500 capitalize">{{ $event->getRawOriginal('frequency') }}</flux:text>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <flux:text size="sm" class="text-zinc-500">No open events.</flux:text>
                    @endif
                </flux:card>
            </a>
        </div>
        @endif
    </div>
</x-layouts.app>
