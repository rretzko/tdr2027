<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>{{ $version->name }}</span>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Web Registration</span>
    </div>

    <div class="mb-6">
        <flux:heading size="xl">Web Registration</flux:heading>
        <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — impersonate an invited teacher, or transfer students between invited teachers</flux:text>
    </div>

    <flux:tab.group>
        <flux:tabs wire:model="activeTab">
            <flux:tab name="impersonate">Impersonate Teacher</flux:tab>
            <flux:tab name="transfer">Transfer Students</flux:tab>
        </flux:tabs>

        {{-- Impersonate Teacher --}}
        <flux:tab.panel name="impersonate">
            <div class="mt-6 max-w-xl space-y-4">
                <flux:callout variant="info" icon="information-circle">
                    <flux:callout.text>You'll see this Version's Registrations pages exactly as the teacher does. You won't have access to their account/profile pages. Use "Return to..." at the top of the page to end the session.</flux:callout.text>
                </flux:callout>

                <flux:field>
                    <flux:label>Teacher</flux:label>
                    <flux:input wire:model.live.debounce.300ms="impersonateSearch" placeholder="Search invited teachers by name..." icon="magnifying-glass" />
                </flux:field>

                @if (trim($impersonateSearch) !== '')
                    @if ($teachersForImpersonation->isEmpty())
                        <flux:text size="sm" class="text-zinc-400">No invited teacher matches that name.</flux:text>
                    @else
                        <div class="flex flex-col gap-2">
                            @foreach ($teachersForImpersonation as $teacher)
                                <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                    <div>
                                        <flux:text class="font-medium">{{ $teacher->user->name }}</flux:text>
                                        <flux:text size="sm" class="text-zinc-500">{{ $teacher->schools->pluck('name')->join(', ') ?: 'No active school' }}</flux:text>
                                    </div>
                                    <flux:button size="sm" wire:click="impersonate({{ $teacher->user->id }})" wire:confirm="Impersonate {{ $teacher->user->name }} for {{ $version->name }}?">
                                        Impersonate
                                    </flux:button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>
        </flux:tab.panel>

        {{-- Transfer Students --}}
        <flux:tab.panel name="transfer">
            <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                <flux:card size="sm" class="!bg-red-50/60 dark:!bg-red-950/20">
                    <flux:heading size="base" class="mb-3">Transfer From</flux:heading>

                    <div class="space-y-3">
                        <flux:select wire:model.live="fromSchoolId" label="School" placeholder="Select a school..." autofocus>
                            @foreach ($schoolOptions as $school)
                                <flux:select.option value="{{ $school->id }}">{{ $school->name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:key="from-teacher-select-{{ $fromSchoolId }}" wire:model.live="fromTeacherId" label="Teacher" placeholder="Select a teacher..." :disabled="$fromSchoolId === null">
                            @foreach ($fromTeacherOptions as $teacher)
                                <flux:select.option wire:key="from-teacher-option-{{ $fromSchoolId }}-{{ $teacher->id }}" value="{{ $teacher->id }}">{{ $teacher->user->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="mt-4" wire:key="from-students-{{ $fromSchoolId }}-{{ $fromTeacherId }}">
                        <flux:label>Students ({{ $fromStudents->count() }})</flux:label>
                        <div class="mt-1 max-h-72 overflow-y-auto space-y-1">
                            @forelse ($fromStudents as $student)
                                <flux:checkbox
                                    wire:key="from-student-{{ $student->id }}"
                                    wire:model.live="selectedStudentIds"
                                    value="{{ $student->id }}"
                                    label="{{ $student->user->name }} ({{ $student->schools->first()?->pivot->class_of }})"
                                />
                            @empty
                                <flux:text size="sm" class="text-zinc-400">No current students for that school/teacher.</flux:text>
                            @endforelse
                        </div>
                    </div>
                </flux:card>

                <flux:card size="sm" class="!bg-emerald-50/60 dark:!bg-emerald-950/20">
                    <flux:heading size="base" class="mb-3">Transfer To</flux:heading>

                    @unless ($fromComplete)
                        <flux:text size="sm" class="text-zinc-500 mb-3">Select a school, teacher, and at least one student on the left first.</flux:text>
                    @endunless

                    <div class="space-y-3">
                        <flux:select wire:model.live="toSchoolId" label="School" placeholder="Select a school..." :disabled="! $fromComplete">
                            @foreach ($schoolOptions as $school)
                                <flux:select.option value="{{ $school->id }}">{{ $school->name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:key="to-teacher-select-{{ $toSchoolId }}" wire:model.live="toTeacherId" label="Teacher" placeholder="Select a teacher..." :disabled="! $fromComplete || $toSchoolId === null">
                            @foreach ($toTeacherOptions as $teacher)
                                <flux:select.option wire:key="to-teacher-option-{{ $toSchoolId }}-{{ $teacher->id }}" value="{{ $teacher->id }}">{{ $teacher->user->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="mt-4" wire:key="to-students-{{ $toSchoolId }}-{{ $toTeacherId }}">
                        <flux:label>Current Students ({{ $toStudents->count() }})</flux:label>
                        <div class="mt-1 max-h-72 overflow-y-auto space-y-1">
                            @forelse ($toStudents as $student)
                                <flux:text size="sm">{{ $student->user->name }} ({{ $student->schools->first()?->pivot->class_of }})</flux:text>
                            @empty
                                <flux:text size="sm" class="text-zinc-400">No current students for that school/teacher.</flux:text>
                            @endforelse
                        </div>
                    </div>
                </flux:card>
            </div>

            <flux:error name="fromSchoolId" />
            <flux:error name="fromTeacherId" />
            <flux:error name="toSchoolId" />
            <flux:error name="toTeacherId" />
            <flux:error name="selectedStudentIds" />

            @if ($errors->any())
                <flux:callout variant="danger" icon="exclamation-triangle" class="mt-4">
                    <flux:callout.text>Please correct the errors above before transferring.</flux:callout.text>
                </flux:callout>
            @endif

            <div class="mt-6">
                <flux:button
                    variant="primary"
                    wire:click="transferStudents"
                    wire:confirm="Transfer {{ count($selectedStudentIds) }} student(s)?"
                    :disabled="count($selectedStudentIds) === 0 || $toSchoolId === null || $toTeacherId === null"
                >
                    Transfer {{ count($selectedStudentIds) }} Student{{ count($selectedStudentIds) === 1 ? '' : 's' }}
                </flux:button>
            </div>
        </flux:tab.panel>
    </flux:tab.group>
</div>
