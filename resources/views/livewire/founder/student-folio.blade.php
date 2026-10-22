<div>
    <div class="mb-6">
        <flux:heading size="xl">Student Folio</flux:heading>
        <flux:subheading>Look up a student to see their account, school and teacher links, and why they are or aren't in each active version.</flux:subheading>
    </div>

    @php($student = $this->student)

    @if ($student === null)
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="Search by name or email…"
            icon="magnifying-glass"
            autofocus
        />

        @if (mb_strlen(trim($search)) >= 2 && $results->isEmpty())
            <flux:text class="mt-2 text-zinc-500">No students match "{{ $search }}".</flux:text>
        @endif

        <div class="mt-2 flex flex-col gap-2">
            @foreach ($results as $result)
                <div wire:key="result-{{ $result->id }}" class="flex items-center justify-between gap-3 rounded-md border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="min-w-0">
                        <flux:text class="font-medium">{{ $result->user->first_name }} {{ $result->user->last_name }} <span class="font-normal text-zinc-500">&middot; Student #{{ $result->id }}</span></flux:text>
                        <flux:text size="sm" class="truncate text-zinc-500">
                            {{ $result->user->email }}
                            @if ($result->schools->isNotEmpty())
                                &middot; {{ $result->schools->pluck('name')->join(', ') }}
                            @endif
                            @if ($result->teachers->isNotEmpty())
                                &middot; {{ $result->teachers->unique('id')->map(fn ($t) => $t->user->last_name)->join(', ') }}
                            @endif
                        </flux:text>
                    </div>
                    <flux:button size="sm" wire:click="select({{ $result->id }})">View</flux:button>
                </div>
            @endforeach
        </div>
    @else
        @php($user = $student->user)
        @php($activeSchools = $student->schools->filter(fn ($s) => $s->pivot->is_active))
        @php($visibleOnAnyRoster = collect($rosterRows)->contains(fn ($row) => $row['visible']))

        {{-- ============================================================
             Identity / account
             ============================================================ --}}
        <div class="mb-6 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="lg">{{ $user->first_name }} {{ $user->middle_name }} {{ $user->last_name }} {{ $user->suffix_name }}</flux:heading>
                        <flux:badge color="blue" size="sm">Student #{{ $student->id }}</flux:badge>
                    </div>
                    <flux:text size="sm" class="text-zinc-500">User #{{ $user->id }}</flux:text>
                </div>
                <flux:button size="sm" variant="outline" icon="arrow-left" wire:click="clear">New search</flux:button>
            </div>

            <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-zinc-500">Email</dt>
                    <dd class="break-all">{{ $user->email }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Email verified</dt>
                    <dd>
                        @if ($user->email_verified_at)
                            <flux:badge color="green" size="sm">{{ $user->email_verified_at->format('M j, Y g:i a') }}</flux:badge>
                        @else
                            <flux:badge color="red" size="sm">Not verified</flux:badge>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Account created</dt>
                    <dd>{{ $user->created_at?->format('M j, Y g:i a') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Last login</dt>
                    <dd>
                        @if ($lastLogin)
                            {{ $lastLogin->created_at->format('M j, Y g:i a') }} ({{ $lastLogin->method->label() }}) &middot; {{ $loginCount }} total
                        @else
                            Never
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Cell phone</dt>
                    <dd>{{ $user->cell_phone ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Pronouns</dt>
                    <dd>{{ $user->pronoun?->description ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Birthday</dt>
                    <dd>{{ $student->birthday?->format('M j, Y') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Grade</dt>
                    <dd>{{ $student->grade ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Voice part / instrument</dt>
                    <dd>{{ $student->voicePart?->name ?? '—' }} / {{ $student->instrument?->name ?? '—' }}</dd>
                </div>
                @if ($student->homeSchool)
                    <div>
                        <dt class="text-zinc-500">Home school</dt>
                        <dd>{{ $student->homeSchool->name }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- Headline problems, so the common causes are visible without scrolling. --}}
        <div class="mb-6 flex flex-col gap-2">
            @if (! $user->email_verified_at)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.text>The student hasn't verified their email address.</flux:callout.text>
                </flux:callout>
            @endif
            @if ($activeSchools->isEmpty())
                <flux:callout variant="danger" icon="x-circle">
                    <flux:callout.text>No active school. The student hasn't finished the SFDI school join, so no teacher can see them.</flux:callout.text>
                </flux:callout>
            @endif
            @if ($rosterRows === [])
                <flux:callout variant="danger" icon="x-circle">
                    <flux:callout.text>Not linked to any teacher (no student_teacher rows).</flux:callout.text>
                </flux:callout>
            @elseif (! $visibleOnAnyRoster)
                <flux:callout variant="danger" icon="x-circle">
                    <flux:callout.text>Linked to teachers, but not visible on any teacher's roster. See Teachers below.</flux:callout.text>
                </flux:callout>
            @endif
        </div>

        {{-- ============================================================
             Schools
             ============================================================ --}}
        <flux:heading size="lg" class="mb-2">Schools</flux:heading>
        @if ($student->schools->isEmpty())
            <flux:text class="mb-6 text-zinc-500">No school links.</flux:text>
        @else
            <div class="mb-6 grid grid-cols-1 gap-3 md:grid-cols-2">
                @foreach ($student->schools as $school)
                    <div wire:key="school-{{ $school->id }}" class="rounded-md border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:text class="font-medium">{{ $school->name }}</flux:text>
                                    <flux:badge color="blue" size="sm">School #{{ $school->id }}</flux:badge>
                                </div>
                                <flux:text size="sm" class="text-zinc-500">{{ $school->city }}</flux:text>
                            </div>
                            <flux:badge :color="$school->pivot->is_active ? 'green' : 'zinc'" size="sm">{{ $school->pivot->is_active ? 'Active' : 'Inactive' }}</flux:badge>
                        </div>
                        <flux:text size="sm" class="mt-2">
                            Class of {{ $school->pivot->class_of }}
                            &middot; Grade {{ \App\Support\ClassOfCalculator::gradeFromClassOf((int) $school->pivot->class_of, $school->senior_year) }}
                            &middot; Joined {{ $school->pivot->created_at?->format('M j, Y') }}
                        </flux:text>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ============================================================
             Teachers / roster visibility
             ============================================================ --}}
        <flux:heading size="lg" class="mb-2">Teachers</flux:heading>
        @if ($rosterRows === [])
            <flux:text class="mb-6 text-zinc-500">No teacher links.</flux:text>
        @else
            <div class="mb-6 grid grid-cols-1 gap-3 md:grid-cols-2">
                @foreach ($rosterRows as $row)
                    @php($link = $row['link'])
                    <div wire:key="link-{{ $link->id }}" class="rounded-md border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:text class="font-medium">{{ $link->teacher->user->first_name }} {{ $link->teacher->user->last_name }}</flux:text>
                                    <flux:badge color="blue" size="sm">Teacher #{{ $link->teacher_id }}</flux:badge>
                                </div>
                                <flux:text size="sm" class="break-all text-zinc-500">{{ $link->teacher->user->email }}</flux:text>
                            </div>
                            @if ($row['visible'])
                                <flux:badge color="green" size="sm" icon="eye">On roster</flux:badge>
                            @else
                                <flux:badge color="red" size="sm" icon="eye-slash">Hidden</flux:badge>
                            @endif
                        </div>
                        <flux:text size="sm" class="mt-2">
                            {{ $link->school->name }} (#{{ $link->school_id }}) &middot; {{ $link->subject->label() }} &middot; {{ ucfirst($link->role->value) }}
                            &middot; Linked {{ $link->created_at?->format('M j, Y') }}
                        </flux:text>
                        <flux:text size="sm" class="mt-1 {{ $row['visible'] ? 'text-zinc-500' : 'text-red-600 dark:text-red-400' }}">{{ $row['reason'] }}</flux:text>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ============================================================
             Active versions
             ============================================================ --}}
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg">Active Versions</flux:heading>
            <flux:button size="sm" icon="arrow-path" wire:click="reEnroll">Re-run auto-enrollment</flux:button>
        </div>
        <flux:text size="sm" class="mb-3 text-zinc-500">The student's SFDI "My Events" page lists only versions where they're a Candidate. Each checklist mirrors the auto-enrollment gates for one of the student's teachers.</flux:text>

        @if ($versionDiagnostics === [])
            <flux:text class="mb-6 text-zinc-500">No versions are currently active.</flux:text>
        @else
            <div class="mb-6 flex flex-col gap-3">
                @foreach ($versionDiagnostics as $diagnostic)
                    @php($version = $diagnostic['version'])
                    @php($candidate = $diagnostic['candidate'])
                    @php($anyInvited = collect($diagnostic['teachers'])->contains(fn ($t) => $t['checks'][0]['passed']))
                    <details wire:key="version-{{ $version->id }}" class="rounded-md border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800" @if ($candidate || $anyInvited) open @endif>
                        <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 p-3">
                            <div class="min-w-0">
                                <flux:text class="font-medium">{{ $version->event->name }} — {{ $version->name }}</flux:text>
                                <flux:text size="sm" class="text-zinc-500">Version #{{ $version->id }}</flux:text>
                            </div>
                            @if ($candidate)
                                <flux:badge color="green" size="sm">Candidate #{{ $candidate->id }} &middot; {{ $candidate->status->label() }}</flux:badge>
                            @elseif ($anyInvited)
                                <flux:badge color="red" size="sm">Not a candidate</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">No teacher invited</flux:badge>
                            @endif
                        </summary>

                        <div class="border-t border-zinc-200 p-3 dark:border-zinc-700">
                            @if ($candidate)
                                <flux:text size="sm" class="mb-3">
                                    Enrolled via {{ $candidate->teacher->user->first_name }} {{ $candidate->teacher->user->last_name }}
                                    at {{ $candidate->school->name }} on {{ $candidate->created_at?->format('M j, Y') }}.
                                </flux:text>
                            @endif

                            @forelse ($diagnostic['teachers'] as $teacherDiagnostic)
                                <div wire:key="version-{{ $version->id }}-teacher-{{ $teacherDiagnostic['teacher']->id }}" class="mb-3 last:mb-0">
                                    <div class="mb-1 flex items-center gap-2">
                                        <flux:text class="font-medium">{{ $teacherDiagnostic['teacher']->user->first_name }} {{ $teacherDiagnostic['teacher']->user->last_name }}</flux:text>
                                        <flux:badge color="blue" size="sm">Teacher #{{ $teacherDiagnostic['teacher']->id }}</flux:badge>
                                        @if ($teacherDiagnostic['eligible'])
                                            <flux:badge color="green" size="sm">All gates pass</flux:badge>
                                        @endif
                                    </div>
                                    <ul class="flex flex-col gap-1 text-sm">
                                        @foreach ($teacherDiagnostic['checks'] as $check)
                                            <li class="flex items-start gap-2">
                                                @if ($check['passed'])
                                                    <flux:icon.check-circle variant="micro" class="mt-0.5 shrink-0 text-green-600 dark:text-green-400" />
                                                @else
                                                    <flux:icon.x-circle variant="micro" class="mt-0.5 shrink-0 text-red-600 dark:text-red-400" />
                                                @endif
                                                <span>
                                                    <span class="font-medium">{{ $check['label'] }}</span>
                                                    <span class="text-zinc-500">— {{ $check['detail'] }}</span>
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @empty
                                <flux:text size="sm" class="text-zinc-500">The student has no teachers to check against.</flux:text>
                            @endforelse
                        </div>
                    </details>
                @endforeach
            </div>
        @endif

        {{-- ============================================================
             Candidate history (non-active versions)
             ============================================================ --}}
        <flux:heading size="lg" class="mb-2">Candidate History</flux:heading>
        @if ($candidateHistory->isEmpty())
            <flux:text class="mb-6 text-zinc-500">No candidacies in inactive or closed versions.</flux:text>
        @else
            <div class="mb-6 flex flex-col gap-2">
                @foreach ($candidateHistory as $past)
                    <div wire:key="history-{{ $past->id }}" class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="min-w-0">
                            <flux:text class="font-medium">{{ $past->version->event->name }} — {{ $past->version->name }}</flux:text>
                            <flux:text size="sm" class="text-zinc-500">
                                {{ $past->teacher->user->first_name }} {{ $past->teacher->user->last_name }} &middot; {{ $past->school->name }} &middot; {{ ucfirst($past->version->status->value) }}
                            </flux:text>
                        </div>
                        <flux:badge size="sm">{{ $past->status->label() }}</flux:badge>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ============================================================
             Possible duplicates
             ============================================================ --}}
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg">Possible Duplicates</flux:heading>
            @if ($duplicates->isNotEmpty())
                <flux:button size="sm" variant="outline" :href="route('founder.merge-students')">Merge Students</flux:button>
            @endif
        </div>
        @if ($duplicates->isEmpty())
            <flux:text class="mb-6 text-zinc-500">No likely duplicate records.</flux:text>
        @else
            <div class="mb-6 flex flex-col gap-2">
                @foreach ($duplicates as $match)
                    <div wire:key="dup-{{ $match['student']->id }}" class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="min-w-0">
                            <flux:text class="font-medium">{{ $match['student']->user->first_name }} {{ $match['student']->user->last_name }}</flux:text>
                            <flux:text size="sm" class="break-all text-zinc-500">{{ $match['student']->user->email }} &middot; Student #{{ $match['student']->id }}</flux:text>
                        </div>
                        <div class="flex items-center gap-2">
                            <flux:badge :color="$match['tier'] === 'strong' ? 'red' : 'amber'" size="sm">{{ ucfirst($match['tier']) }} match</flux:badge>
                            <flux:button size="sm" wire:click="select({{ $match['student']->id }})">View</flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ============================================================
             Contacts & address
             ============================================================ --}}
        <flux:heading size="lg" class="mb-2">Contacts &amp; Address</flux:heading>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div class="rounded-md border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:text class="mb-1 font-medium">Home address</flux:text>
                @if ($student->homeAddress)
                    <flux:text size="sm">
                        {{ $student->homeAddress->address1 }}@if ($student->homeAddress->address2), {{ $student->homeAddress->address2 }}@endif<br>
                        {{ $student->homeAddress->city }}, {{ $student->homeAddress->geo_state }} {{ $student->homeAddress->zip_code }}
                    </flux:text>
                @else
                    <flux:text size="sm" class="text-zinc-500">None on file.</flux:text>
                @endif
            </div>

            @forelse ($student->emergencyContacts as $contact)
                <div wire:key="contact-{{ $contact->id }}" class="rounded-md border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:text class="mb-1 font-medium">{{ $contact->name }} <span class="font-normal text-zinc-500">({{ $contact->relationship?->label() ?? '—' }})</span></flux:text>
                    <flux:text size="sm" class="break-all">{{ $contact->email ?: '—' }}</flux:text>
                    <flux:text size="sm">
                        Cell {{ $contact->cell_phone ?: '—' }} &middot; Home {{ $contact->home_phone ?: '—' }} &middot; Work {{ $contact->work_phone ?: '—' }}
                    </flux:text>
                </div>
            @empty
                <div class="rounded-md border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:text class="mb-1 font-medium">Emergency contacts</flux:text>
                    <flux:text size="sm" class="text-zinc-500">None on file.</flux:text>
                </div>
            @endforelse
        </div>
    @endif
</div>
