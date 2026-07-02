<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('registrations.index') }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Registrations</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>{{ $version->name }}</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">{{ $version->name }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->event->name }}</flux:text>
        </div>

        @php $vs = $version->getRawOriginal('status'); @endphp
        @if ($vs === 'active')
            <flux:badge color="green">Active</flux:badge>
        @elseif ($vs === 'sandbox')
            <flux:badge color="amber">Sandbox</flux:badge>
        @else
            <flux:badge color="zinc" class="capitalize">{{ $vs }}</flux:badge>
        @endif
    </div>

    {{-- Upcoming dates --}}
    @if ($upcomingDates->isNotEmpty())
        <div class="mb-6">
            <flux:heading size="sm" class="mb-2">Upcoming Dates</flux:heading>
            <div class="flex flex-wrap gap-2">
                @foreach ($upcomingDates as $date)
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 px-3 py-2 text-sm">
                        <span class="font-medium text-zinc-700 dark:text-zinc-300">
                            {{ $date->dateType?->label() ?? $date->getRawOriginal('date_type') }}
                        </span>
                        <span class="text-zinc-500 ml-2">
                            {{ \Carbon\Carbon::parse($date->getRawOriginal('start_at'))->format('M j, Y') }}
                            @if ($date->getRawOriginal('end_at'))
                                – {{ \Carbon\Carbon::parse($date->getRawOriginal('end_at'))->format('M j, Y') }}
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Candidates --}}
    <div class="mb-8">
        <flux:heading size="lg" class="mb-3">
            My Candidates
            @if ($myCandidates->isNotEmpty())
                <flux:badge color="zinc" size="sm" class="ml-2">{{ $myCandidates->count() }}</flux:badge>
            @endif
        </flux:heading>

        @if ($myCandidates->isEmpty())
            <flux:text class="text-zinc-500">No candidates yet. Enroll a student below.</flux:text>
        @else
            {{-- Cards below md:, table at md:+ --}}
            <div class="md:hidden space-y-3">
                @foreach ($myCandidates as $candidate)
                    @php
                        $rawStatus = $candidate->getRawOriginal('status');
                        $allDone = collect($checklistDefs)->every(fn ($def) => ($def['check'])($candidate));
                    @endphp
                    <flux:card size="sm">
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <div>
                                <flux:heading size="base">{{ $candidate->program_name }}</flux:heading>
                                <flux:text size="sm" class="text-zinc-400 font-mono">{{ $candidate->ref }}</flux:text>
                            </div>
                            <div class="flex flex-col items-end gap-1">
                                @if ($rawStatus === 'eligible')
                                    <flux:badge color="zinc" size="sm">Eligible</flux:badge>
                                @elseif ($rawStatus === 'pending')
                                    <flux:badge color="amber" size="sm">Pending</flux:badge>
                                @elseif ($rawStatus === 'registered')
                                    <flux:badge color="green" size="sm">Registered</flux:badge>
                                @elseif ($rawStatus === 'teacher_withdrawn')
                                    <flux:badge color="red" size="sm">Withdrawn</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm" class="capitalize">{{ str_replace('_', ' ', $rawStatus) }}</flux:badge>
                                @endif
                            </div>
                        </div>

                        {{-- Poka-yoke checklist --}}
                        <div class="flex flex-wrap gap-2 mb-3">
                            @foreach ($checklistDefs as $def)
                                @php $done = ($def['check'])($candidate); @endphp
                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium
                                    {{ $done ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400' }}">
                                    @if ($done)
                                        <flux:icon.check-circle variant="micro" />
                                    @else
                                        <flux:icon.x-circle variant="micro" />
                                    @endif
                                    {{ $def['label'] }}
                                </span>
                            @endforeach
                        </div>

                        <div class="flex gap-2">
                            <flux:button size="sm" variant="ghost"
                                :href="route('registrations.candidate', [$version, $candidate])"
                                wire:navigate>
                                Manage
                            </flux:button>
                            @if (in_array($rawStatus, ['eligible', 'pending', 'registered']))
                                <flux:button size="sm" variant="ghost" icon="arrow-path"
                                    wire:click="refreshStatus({{ $candidate->id }})">
                                    Refresh
                                </flux:button>
                            @endif
                            <flux:button size="sm" variant="ghost"
                                wire:click="withdraw({{ $candidate->id }})"
                                wire:confirm="Withdraw {{ $candidate->program_name }}? Their status will be set to Teacher Withdrawn.">
                                Withdraw
                            </flux:button>
                        </div>
                    </flux:card>
                @endforeach
            </div>

            <flux:table class="hidden md:table">
                <flux:table.columns>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Ref</flux:table.column>
                    <flux:table.column>Voice Part</flux:table.column>
                    <flux:table.column>Checklist</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($myCandidates as $candidate)
                        @php
                            $rawStatus = $candidate->getRawOriginal('status');
                            $allDone = collect($checklistDefs)->every(fn ($def) => ($def['check'])($candidate));
                        @endphp
                        <flux:table.row>
                            <flux:table.cell class="font-medium">{{ $candidate->program_name }}</flux:table.cell>
                            <flux:table.cell class="font-mono text-zinc-500">{{ $candidate->ref }}</flux:table.cell>
                            <flux:table.cell>{{ $candidate->voicePart?->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($checklistDefs as $def)
                                        @php $done = ($def['check'])($candidate); @endphp
                                        <span class="inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-medium
                                            {{ $done ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400' }}">
                                            @if ($done)
                                                <flux:icon.check-circle variant="micro" />
                                            @else
                                                <flux:icon.x-circle variant="micro" />
                                            @endif
                                            {{ $def['label'] }}
                                        </span>
                                    @endforeach
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($rawStatus === 'eligible')
                                    <flux:badge color="zinc" size="sm">Eligible</flux:badge>
                                @elseif ($rawStatus === 'pending')
                                    <flux:badge color="amber" size="sm">Pending</flux:badge>
                                @elseif ($rawStatus === 'registered')
                                    <flux:badge color="green" size="sm">Registered</flux:badge>
                                @elseif ($rawStatus === 'teacher_withdrawn')
                                    <flux:badge color="red" size="sm">Withdrawn</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm" class="capitalize">{{ str_replace('_', ' ', $rawStatus) }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" variant="ghost"
                                        :href="route('registrations.candidate', [$version, $candidate])"
                                        wire:navigate>
                                        Manage
                                    </flux:button>
                                    @if (in_array($rawStatus, ['eligible', 'pending', 'registered']))
                                        <flux:button size="sm" variant="ghost" icon="arrow-path"
                                            wire:click="refreshStatus({{ $candidate->id }})">
                                            Refresh
                                        </flux:button>
                                    @endif
                                    <flux:button size="sm" variant="ghost"
                                        wire:click="withdraw({{ $candidate->id }})"
                                        wire:confirm="Withdraw {{ $candidate->program_name }}? Their status will be set to Teacher Withdrawn.">
                                        Withdraw
                                    </flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    {{-- Enroll a student --}}
    @if ($eligibleStudents->isNotEmpty())
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-xl p-5 max-w-lg">
            <flux:heading size="base" class="mb-4">Enroll a Student</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>Student</flux:label>
                    <flux:select wire:model="enroll_student_id">
                        <flux:select.option value="">— select student —</flux:select.option>
                        @foreach ($eligibleStudents as $student)
                            <flux:select.option value="{{ $student->id }}">
                                {{ $student->user->last_name }}, {{ $student->user->first_name }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="enroll_student_id" />
                </flux:field>

                <flux:field>
                    <flux:label>Voice Part</flux:label>
                    <flux:select wire:model="enroll_voice_part_id">
                        <flux:select.option value="">— select voice part —</flux:select.option>
                        @foreach ($voiceParts as $vp)
                            <flux:select.option value="{{ $vp->id }}">{{ $vp->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="enroll_voice_part_id" />
                </flux:field>

                <flux:button variant="primary" wire:click="enroll">
                    Enroll Student
                </flux:button>
            </div>
        </div>
    @elseif ($myCandidates->isEmpty())
        <flux:callout variant="info" icon="information-circle">
            <flux:callout.text>
                No eligible students found. Students must be actively enrolled in one of your verified schools and linked to you.
            </flux:callout.text>
        </flux:callout>
    @else
        <flux:text size="sm" class="text-zinc-400">All eligible students have been enrolled.</flux:text>
    @endif

</div>
