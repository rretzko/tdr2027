<div>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <flux:heading size="xl">Students</flux:heading>

        <div class="flex items-center gap-3">
            <flux:button id="tour-start" data-auto-start="{{ auth()->user()->dismissed_students_orientation_at === null ? '1' : '0' }}" size="sm" variant="ghost" icon="sparkles" type="button">Take a tour</flux:button>

            <flux:input id="tour-search" wire:model.live.debounce.300ms="search" placeholder="Search by name..." icon="magnifying-glass" class="sm:max-w-xs" />

            @if ($filterSchools->count() > 1)
                <flux:select id="tour-school-filter" wire:model.live="schoolFilter" placeholder="All schools" class="sm:max-w-xs">
                    <flux:select.option value="">All schools</flux:select.option>
                    @foreach ($filterSchools as $filterSchool)
                        <flux:select.option value="{{ $filterSchool->id }}">{{ $filterSchool->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:modal.trigger name="edit-student">
                <flux:button id="tour-add-student" variant="primary" icon="plus" wire:click="add">
                    Add student
                </flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    {{-- Cards below lg:, full table at lg: and up. This table has 9 columns —
         md: doesn't leave enough room once the persistent sidebar appears. --}}
    <div id="tour-roster-mobile" class="lg:hidden space-y-3">
        @forelse ($rows as $row)
            <flux:card size="sm">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        @php $avatar = $row->student->user->avatarUrl(); @endphp
                        @if ($avatar)
                            <img src="{{ $avatar }}" alt="" class="h-10 w-10 shrink-0 rounded-full object-cover">
                        @else
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-sm font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                {{ \App\Support\NameFormatter::initials($row->student->user) }}
                            </div>
                        @endif

                        <div>
                            <flux:heading size="base">{{ $this->studentDisplayName($row->student->user) }}</flux:heading>

                            @if ($this->hasRealEmail($row->student->user->email))
                                <flux:text size="sm" class="ms-3 text-zinc-500">{{ $row->student->user->email }}</flux:text>
                            @else
                                <flux:text size="sm" class="ms-3 italic text-zinc-400">No email address</flux:text>
                            @endif

                            <flux:text size="sm" class="text-zinc-500">{{ $row->school->name }}</flux:text>
                        </div>
                    </div>

                    @if ($row->isPending())
                        <flux:badge id="tour-status-mobile" color="amber" size="sm">Pending</flux:badge>
                    @elseif ($row->is_active)
                        <flux:badge id="tour-status-mobile" color="green" size="sm">Active</flux:badge>
                    @else
                        <flux:badge id="tour-status-mobile" color="zinc" size="sm">Inactive</flux:badge>
                    @endif
                </div>

                <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                    <div>
                        <dt class="text-zinc-400">Subject</dt>
                        <dd>{{ $row->subject->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-400">Grade</dt>
                        <dd>{{ $gradeByRowId[$row->id] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-400">Voice Part</dt>
                        <dd>{{ $row->student->voicePart?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-400">Home Address</dt>
                        <dd>
                            @if ($row->student->homeAddress)
                                <flux:badge color="green" size="sm">Yes</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">No</flux:badge>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-zinc-400">Emergency Contact</dt>
                        <dd>
                            @if ($row->student->emergencyContacts->isNotEmpty())
                                <flux:badge color="green" size="sm">Yes</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">No</flux:badge>
                            @endif
                        </dd>
                    </div>
                </dl>

                <div id="tour-actions-mobile" class="mt-4 grid grid-cols-3 gap-2">
                    <flux:modal.trigger name="edit-student" :disabled="$row->isPending()">
                        <flux:button size="sm" variant="outline" class="w-full" :disabled="$row->isPending()" wire:click="edit({{ $row->id }})">
                            Edit
                        </flux:button>
                    </flux:modal.trigger>
                    <flux:button size="sm" variant="outline" :disabled="! $row->is_active" wire:click="deactivate({{ $row->id }})">
                        Deactivate
                    </flux:button>
                    <flux:button
                        size="sm"
                        variant="danger"
                        wire:click="remove({{ $row->id }})"
                        wire:confirm="{{ $row->isPending() ? 'Cancel this pending request?' : 'Remove '.$row->student->user->name.' from your roster? This cannot be undone.' }}"
                    >
                        {{ $row->isPending() ? 'Cancel request' : 'Remove' }}
                    </flux:button>
                </div>
            </flux:card>
        @empty
            <flux:card size="sm" class="text-center text-zinc-500">
                No students found.
            </flux:card>
        @endforelse

        <flux:pagination :paginator="$rows" />
    </div>

    <div id="tour-roster-desktop" class="hidden lg:block">
        <flux:table :paginate="$rows">
            <flux:table.columns>
                <flux:table.column>Photo</flux:table.column>
                <flux:table.column sortable :sorted="$sortColumn === 'name'" :direction="$sortDirection" wire:click="sortBy('name')">
                    Name
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortColumn === 'school'" :direction="$sortDirection" wire:click="sortBy('school')">
                    School
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortColumn === 'subject'" :direction="$sortDirection" wire:click="sortBy('subject')">
                    Subject
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortColumn === 'grade'" :direction="$sortDirection" wire:click="sortBy('grade')">
                    Grade
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortColumn === 'voice_part'" :direction="$sortDirection" wire:click="sortBy('voice_part')">
                    Voice Part
                </flux:table.column>
                <flux:table.column>Address &amp; Contact</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column align="center">Actions</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($rows as $row)
                    <flux:table.row :key="$row->id">
                        <flux:table.cell>
                            @php $avatar = $row->student->user->avatarUrl(); @endphp
                            @if ($avatar)
                                <img src="{{ $avatar }}" alt="" class="h-8 w-8 rounded-full object-cover">
                            @else
                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-zinc-200 text-xs font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                    {{ \App\Support\NameFormatter::initials($row->student->user) }}
                                </div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div>{{ $this->studentDisplayName($row->student->user) }}</div>

                            <div class="mt-0.5 ms-3">
                                @if ($this->hasRealEmail($row->student->user->email))
                                    <flux:text size="sm" class="text-zinc-500">{{ $row->student->user->email }}</flux:text>
                                @else
                                    <flux:text size="sm" class="italic text-zinc-400">No email address</flux:text>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $row->school->name }}</flux:table.cell>
                        <flux:table.cell>{{ $row->subject->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $gradeByRowId[$row->id] ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $row->student->voicePart?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-col gap-1 text-xs">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-zinc-400">Address:</span>
                                    @if ($row->student->homeAddress)
                                        <flux:badge color="green" size="sm">Yes</flux:badge>
                                    @else
                                        <flux:badge color="zinc" size="sm">No</flux:badge>
                                    @endif
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-zinc-400">Contact:</span>
                                    @if ($row->student->emergencyContacts->isNotEmpty())
                                        <flux:badge color="green" size="sm">Yes</flux:badge>
                                    @else
                                        <flux:badge color="zinc" size="sm">No</flux:badge>
                                    @endif
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell id="tour-status-desktop">
                            @if ($row->isPending())
                                <flux:badge color="amber" size="sm">Pending</flux:badge>
                            @elseif ($row->is_active)
                                <flux:badge color="green" size="sm">Active</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell id="tour-actions-desktop">
                            <div class="flex items-center justify-center gap-1">
                                <flux:modal.trigger name="edit-student" :disabled="$row->isPending()">
                                    <flux:button size="sm" variant="ghost" icon="pencil" aria-label="Edit student" :disabled="$row->isPending()" wire:click="edit({{ $row->id }})" />
                                </flux:modal.trigger>

                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" aria-label="Student actions" />

                                    <flux:menu>
                                        <flux:menu.item :disabled="! $row->is_active" wire:click="deactivate({{ $row->id }})">
                                            Deactivate
                                        </flux:menu.item>
                                        <flux:menu.item
                                            variant="danger"
                                            wire:click="remove({{ $row->id }})"
                                            wire:confirm="{{ $row->isPending() ? 'Cancel this pending request?' : 'Remove '.$row->student->user->name.' from your roster? This cannot be undone.' }}"
                                        >
                                            {{ $row->isPending() ? 'Cancel request' : 'Remove' }}
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="9" class="text-center text-zinc-500">
                            No students found.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="edit-student" scroll="body" class="md:w-[36rem] border-2 border-zinc-300 dark:border-zinc-400">
        <form wire:submit="{{ $attachingStudentId !== null ? 'attachExistingStudent' : ($claimingStudentId !== null ? 'submitStudentClaim' : ($isAdding ? 'saveAdd' : 'saveEdit')) }}" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    @if ($attachingStudentId !== null || $claimingStudentId !== null)
                        Add existing student to your roster
                    @else
                        {{ $isAdding ? 'Add student' : 'Edit student' }}
                    @endif
                </flux:heading>
                <flux:subheading>
                    @if ($attachingStudentId !== null)
                        {{ $attachingStudentName }} is already in the system — you'll be added as a teacher for them, not creating a new student record.
                    @elseif ($claimingStudentId !== null)
                        @if ($claimWillAutoApprove)
                            {{ $claimingStudentName }} is in the system at {{ $claimingStudentSchoolName }} but has no active teacher — they'll be added directly to your roster.
                        @else
                            {{ $claimingStudentName }} is already a student of another teacher at {{ $claimingStudentSchoolName }}. They'll need to approve this before you're added.
                        @endif
                    @elseif ($isAdding)
                        Add a new student to your roster.
                    @else
                        Update this student's profile, contacts, and your role with them.
                    @endif
                </flux:subheading>
            </div>

            @if ($attachingStudentId !== null)
                <flux:input value="{{ $attachingStudentName }}" label="Student" disabled />
                <flux:input value="{{ $attachingStudentSchoolName }}" :label="$this->schoolOrStudioLabel()" disabled />
                @if ($attachingStudentGrade !== null)
                    <flux:input value="{{ $attachingStudentGrade }}th grade" label="Grade" disabled />
                @endif

                <flux:separator text="Your role with this student" />

                <flux:select wire:model.live="edit_subject" label="Subject" variant="listbox" multiple placeholder="Select subjects...">
                    @foreach ($subjectOptions as $subject)
                        <flux:select.option value="{{ $subject->value }}">{{ $subject->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="edit_role" label="Your role">
                    <flux:select.option value="primary">Primary teacher / director</flux:select.option>
                    <flux:select.option value="coteacher">Co-teacher / assistant director</flux:select.option>
                </flux:select>

                @if ($errors->any())
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.text>This form has not been saved. Please fix the highlighted fields above before saving.</flux:callout.text>
                    </flux:callout>
                @endif

                <div class="flex items-center gap-2">
                    <flux:button variant="ghost" wire:click="cancelAttachExistingStudent">Back</flux:button>
                    <flux:spacer />
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">Add to my roster</flux:button>
                </div>
            @elseif ($claimingStudentId !== null)
                <flux:input value="{{ $claimingStudentName }}" label="Student" disabled />
                <flux:input value="{{ $claimingStudentSchoolName }}" label="Currently at" disabled />

                <flux:select wire:model="claim_grade" label="Grade" placeholder="Select a grade..." required>
                    @foreach ($this->addGradeOptions() as $option)
                        <flux:select.option value="{{ $option['grade'] }}">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="claim_grade" />

                <flux:separator text="Your role with this student" />

                <flux:select wire:model.live="edit_subject" label="Subject" variant="listbox" multiple placeholder="Select subjects...">
                    @foreach ($subjectOptions as $subject)
                        <flux:select.option value="{{ $subject->value }}">{{ $subject->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="edit_role" label="Your role">
                    <flux:select.option value="primary">Primary teacher / director</flux:select.option>
                    <flux:select.option value="coteacher">Co-teacher / assistant director</flux:select.option>
                </flux:select>

                @if ($claimWillAutoApprove)
                    <flux:callout variant="info" icon="information-circle">
                        <flux:callout.text>{{ $claimingStudentName }} has no active teacher in the system — they'll be added directly to your roster without requiring approval.</flux:callout.text>
                    </flux:callout>
                @else
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.text>Click the 'Send Request' button to send an email to {{ $claimingStudentName }}'s current teacher to approve or deny this request. The student will display in Pending status on your roster until that teacher approves the request. If the current teacher denies the request, the student's name will be removed from your roster.</flux:callout.text>
                    </flux:callout>
                @endif

                @if ($errors->any())
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.text>This form has not been saved. Please fix the highlighted fields above before saving.</flux:callout.text>
                    </flux:callout>
                @endif

                <div class="flex items-center gap-2">
                    <flux:button variant="ghost" wire:click="cancelStudentClaim">Back</flux:button>
                    <flux:spacer />
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ $claimWillAutoApprove ? 'Add to my roster' : 'Send request' }}</flux:button>
                </div>
            @else
                @if ($emailFallbackNotice)
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.text>{{ $emailFallbackNotice }}</flux:callout.text>
                    </flux:callout>
                @endif

            @if ($isAdding)
                <flux:separator :text="$this->schoolOrStudioLabel()" />

                <flux:select wire:model.live="add_school_id" :label="$this->schoolOrStudioLabel()" placeholder="Select a school..." required>
                    @foreach ($addSchoolOptions as $school)
                        <flux:select.option value="{{ $school->id }}">{{ $school->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="add_grade" label="Grade" placeholder="Select a grade..." required>
                    @foreach ($this->addGradeOptions() as $option)
                        <flux:select.option value="{{ $option['grade'] }}">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
            @else
                <flux:separator :text="$this->schoolOrStudioLabel()" />

                <flux:input value="{{ $editingSchoolName }}" :label="$this->schoolOrStudioLabel()" disabled />

                <flux:separator text="Grade" />

                <flux:select wire:model="edit_grade" label="Grade" placeholder="Select a grade..." required>
                    @foreach ($this->editGradeOptions() as $option)
                        <flux:select.option value="{{ $option['grade'] }}">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            @if ($this->isStudioContext())
                <flux:separator text="Student's School" />

                @if ($edit_home_school_id !== '')
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 p-3 dark:border-white/10">
                        <flux:text class="font-medium">{{ $edit_home_school_name }}</flux:text>
                        <flux:button size="sm" variant="ghost" wire:click="changeHomeSchool">Change</flux:button>
                    </div>
                @else
                    <flux:field>
                        <flux:label>
                            Student's school
                            <flux:tooltip content="The school where this student takes their regular class with their school teacher — used to flag event scheduling conflicts.">
                                <flux:icon.question-mark-circle variant="micro" class="inline text-zinc-400" />
                            </flux:tooltip>
                        </flux:label>
                        <flux:input wire:model.live.debounce.300ms="edit_home_school_name" placeholder="Start typing a school name..." />
                        <flux:error name="edit_home_school_name" />
                    </flux:field>

                    @unless ($edit_home_school_confirmed_new)
                        @if ($this->homeSchoolSuggestions()->isNotEmpty())
                            <div class="flex flex-col gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950/40">
                                <flux:text size="sm" class="font-medium">Did you mean:</flux:text>

                                @foreach ($this->homeSchoolSuggestions() as $match)
                                    <div class="flex items-center justify-between gap-3 rounded-md border border-zinc-200 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-800">
                                        <div>
                                            <flux:text class="font-medium">{{ $match['school']->name }}</flux:text>
                                            <flux:text size="sm" class="text-zinc-500">{{ $match['school']->city }}, {{ $match['school']->zip_code }}</flux:text>
                                        </div>
                                        <flux:button size="sm" wire:click="selectHomeSchool({{ $match['school']->id }})">This is it</flux:button>
                                    </div>
                                @endforeach

                                <flux:button size="sm" variant="ghost" wire:click="confirmNewHomeSchool">
                                    None of these — add a new school
                                </flux:button>
                            </div>
                        @elseif (trim($edit_home_school_name) !== '')
                            <flux:button size="sm" variant="ghost" wire:click="confirmNewHomeSchool">
                                Add "{{ $edit_home_school_name }}" as a new school
                            </flux:button>
                        @endif
                    @endunless

                    @if ($edit_home_school_confirmed_new)
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <flux:input wire:model="edit_home_school_city" label="City" />
                            <flux:input wire:model="edit_home_school_zip_code" label="Zip code" />
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <flux:select wire:model.live="edit_home_school_geostate_id" label="State">
                                <flux:select.option value="">Select a state...</flux:select.option>
                                @foreach ($geostates as $geostate)
                                    <flux:select.option value="{{ $geostate->id }}">{{ $geostate->name }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:select wire:model="edit_home_school_county_id" label="County" placeholder="Select a county...">
                                @foreach ($homeSchoolCounties as $county)
                                    <flux:select.option value="{{ $county->id }}">{{ $county->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>

                        <flux:button size="sm" variant="ghost" wire:click="cancelNewHomeSchool">Cancel</flux:button>
                    @endif
                @endif
            @endif

            <flux:separator text="Profile" />

            @unless ($isAdding)
                <div class="flex items-center gap-4">
                    @php $editAvatar = $this->editingUser()?->avatarUrl(); @endphp
                    @if ($editAvatar)
                        <img src="{{ $editAvatar }}" alt="" class="h-16 w-16 rounded-full object-cover">
                    @else
                        <div class="flex h-16 w-16 items-center justify-center rounded-full bg-zinc-200 text-lg font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                            {{ $this->editingUser() !== null ? \App\Support\NameFormatter::initials($this->editingUser()) : '?' }}
                        </div>
                    @endif

                    <div class="flex flex-col gap-2">
                        <input
                            type="file"
                            wire:model="edit_photo"
                            accept="image/*"
                            class="block text-sm text-zinc-600 file:mr-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-300"
                        />
                        <div wire:loading wire:target="edit_photo">
                            <flux:text size="sm" class="text-zinc-400">Uploading…</flux:text>
                        </div>
                        <flux:error name="edit_photo" />

                        @if ($this->editingUser()?->photo_path !== null)
                            <flux:button size="sm" variant="ghost" wire:click="removeEditPhoto" wire:confirm="Remove this student's photo?">
                                Remove photo
                            </flux:button>
                        @endif
                    </div>
                </div>
            @endunless

            <flux:input wire:model.live.debounce.300ms="edit_first_name" label="First name" />
            <flux:input wire:model="edit_middle_name" label="Middle name (optional)" />
            <flux:input wire:model.live.debounce.300ms="edit_last_name" label="Last name" />
            <flux:input wire:model="edit_suffix_name" label="Suffix (optional)" />

            @if ($isAdding && $this->unresolvedStudentMatches()->isNotEmpty())
                <div class="flex flex-col gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950/40">
                    <flux:text size="sm" class="font-medium">This may already be one of your students:</flux:text>

                    @foreach ($this->unresolvedStudentMatches() as $match)
                        <div class="flex items-center justify-between gap-3 rounded-md border border-zinc-200 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-800">
                            <div>
                                <flux:text class="font-medium">{{ $match['student']->user->name }}</flux:text>
                                <flux:text size="sm" class="text-zinc-500">
                                    Currently at {{ $this->studentMatchCurrentSchoolName($match['student']) }}
                                    @if ($this->studentMatchGrade($match['student']) !== null)
                                        &middot; {{ $this->studentMatchGrade($match['student']) }}th grade
                                    @endif
                                </flux:text>
                            </div>

                            <div class="flex items-center gap-2">
                                @if ($this->studentMatchIsSameSchool($match['student']))
                                    <flux:button size="sm" wire:click="selectStudentMatch({{ $match['student']->id }})">This is my student</flux:button>
                                @else
                                    <flux:button size="sm" wire:click="selectStudentClaim({{ $match['student']->id }})">Request to add</flux:button>
                                @endif
                                <flux:button size="sm" variant="ghost" wire:click="dismissStudentMatch({{ $match['student']->id }})">Not this student</flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <flux:select wire:model="edit_pronoun_id" label="Pronouns" placeholder="Select pronouns..." required>
                @foreach ($pronouns as $pronoun)
                    <flux:select.option value="{{ $pronoun->id }}">{{ $pronoun->description }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:separator />

            <flux:input wire:model="edit_email" type="email" label="Email" description="Students aren't required to verify their email. If this address is already used by another account, a default address will be assigned instead." />

            <flux:field>
                <flux:label>
                    Cell phone (optional)
                    <flux:tooltip content="May be required for specific events.">
                        <flux:icon.question-mark-circle variant="micro" class="inline text-zinc-400" />
                    </flux:tooltip>
                </flux:label>
                <flux:input
                    wire:model="edit_cell_phone"
                    type="tel"
                    mask:dynamic="$input.replace(/\D/g, '').length > 10 ? '(999) 999-9999 x9999' : '(999) 999-9999'"
                />
                <flux:error name="edit_cell_phone" />
            </flux:field>

            <flux:separator />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <flux:field>
                    <flux:label>
                        Birthday{{ $this->studentAge() !== null ? ' ('.$this->studentAge().' years old)' : '' }}
                        <flux:tooltip content="May be required for specific events.">
                            <flux:icon.question-mark-circle variant="micro" class="inline text-zinc-400" />
                        </flux:tooltip>
                    </flux:label>
                    <flux:input wire:model.live="edit_birthday" type="date" />
                    <flux:error name="edit_birthday" />
                </flux:field>

                <flux:field>
                    <flux:label>
                        Height (in)
                        <flux:tooltip content="May be required for specific events.">
                            <flux:icon.question-mark-circle variant="micro" class="inline text-zinc-400" />
                        </flux:tooltip>
                    </flux:label>
                    <flux:select wire:model="edit_height" placeholder="Select height...">
                        @for ($inches = 30; $inches <= 84; $inches++)
                            <flux:select.option value="{{ $inches }}">{{ $inches }}" ({{ intdiv($inches, 12) }}' {{ $inches % 12 }}")</flux:select.option>
                        @endfor
                    </flux:select>
                    <flux:error name="edit_height" />
                </flux:field>

                <flux:field>
                    <flux:label>
                        Shirt size
                        <flux:tooltip content="May be required for specific events.">
                            <flux:icon.question-mark-circle variant="micro" class="inline text-zinc-400" />
                        </flux:tooltip>
                    </flux:label>
                    <flux:select wire:model="edit_shirt_size" placeholder="Select shirt size...">
                        @foreach ($shirtSizeOptions as $size)
                            <flux:select.option value="{{ $size->value }}">{{ $size->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="edit_shirt_size" />
                </flux:field>
            </div>

            @if (array_intersect($edit_subject, ['band', 'orchestra']) !== [])
                <flux:field>
                    <flux:label>
                        Instrument (optional)
                        <flux:tooltip content="Used to set the default instrument on events">
                            <flux:icon.question-mark-circle variant="micro" class="inline text-zinc-400" />
                        </flux:tooltip>
                    </flux:label>
                    <flux:select wire:model="edit_instrument_id" placeholder="Select an instrument...">
                        @foreach ($instruments as $instrument)
                            <flux:select.option value="{{ $instrument->id }}">{{ $instrument->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="edit_instrument_id" />
                </flux:field>
            @endif

            @if (in_array('chorus', $edit_subject, true))
                <flux:field>
                    <flux:label>
                        Voice part (optional)
                        <flux:tooltip content="Used to set the default voice part on events">
                            <flux:icon.question-mark-circle variant="micro" class="inline text-zinc-400" />
                        </flux:tooltip>
                    </flux:label>
                    <flux:select wire:model="edit_voice_part_id" placeholder="Select a voice part...">
                        @foreach ($voiceParts as $voicePart)
                            <flux:select.option value="{{ $voicePart->id }}">{{ $voicePart->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="edit_voice_part_id" />
                </flux:field>
            @endif

            <div class="flex w-full items-center" role="none">
                <div class="h-px w-full grow border-0 bg-zinc-800/15 [print-color-adjust:exact] dark:bg-white/20"></div>
                <span class="mx-6 flex shrink-0 items-center gap-1 text-sm font-medium whitespace-nowrap text-zinc-500 dark:text-zinc-300">
                    Home address (optional)
                    <flux:tooltip content="May be required for specific events.">
                        <flux:icon.question-mark-circle variant="micro" class="inline text-zinc-400" />
                    </flux:tooltip>
                </span>
                <div class="h-px w-full grow border-0 bg-zinc-800/15 [print-color-adjust:exact] dark:bg-white/20"></div>
            </div>

            <flux:input wire:model="edit_home_address1" label="Address line 1" />
            <flux:input wire:model="edit_home_address2" label="Address line 2 (optional)" />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <flux:input wire:model="edit_home_city" label="City" class="sm:col-span-1" />
                <flux:input wire:model="edit_home_geo_state" label="State" maxlength="2" />
                <flux:input wire:model="edit_home_zip_code" label="Zip code" />
            </div>

            <flux:separator :text="$isAdding ? 'Emergency contacts (optional)' : 'Emergency contacts'" />

            @foreach ($edit_emergency_contacts as $index => $contact)
                <div class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-white/10">
                    <div class="flex items-center justify-between">
                        <flux:text class="font-medium">Contact {{ $index + 1 }}</flux:text>

                        @if (count($edit_emergency_contacts) > 1)
                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeEmergencyContactRow({{ $index }})" aria-label="Remove contact" />
                        @endif
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <flux:input wire:model="edit_emergency_contacts.{{ $index }}.name" label="Name" />
                        <flux:select wire:model="edit_emergency_contacts.{{ $index }}.relationship" label="Relationship" placeholder="Select a relationship...">
                            @foreach ($relationshipOptions as $relationship)
                                <flux:select.option value="{{ $relationship->value }}">{{ $relationship->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <flux:input wire:model="edit_emergency_contacts.{{ $index }}.email" type="email" label="Email" />

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <flux:input
                            wire:model="edit_emergency_contacts.{{ $index }}.cell_phone"
                            label="Cell phone"
                            type="tel"
                            mask:dynamic="$input.replace(/\D/g, '').length > 10 ? '(999) 999-9999 x9999' : '(999) 999-9999'"
                        />
                        <flux:input
                            wire:model="edit_emergency_contacts.{{ $index }}.home_phone"
                            label="Home phone (optional)"
                            type="tel"
                            mask:dynamic="$input.replace(/\D/g, '').length > 10 ? '(999) 999-9999 x9999' : '(999) 999-9999'"
                        />
                        <flux:input
                            wire:model="edit_emergency_contacts.{{ $index }}.work_phone"
                            label="Work phone (optional)"
                            type="tel"
                            mask:dynamic="$input.replace(/\D/g, '').length > 10 ? '(999) 999-9999 x9999' : '(999) 999-9999'"
                        />
                    </div>
                </div>
            @endforeach

            <flux:button size="sm" variant="ghost" icon="plus" wire:click="addEmergencyContactRow">
                Add another contact
            </flux:button>

            <flux:separator text="Your role with this student" />

            <flux:select wire:model.live="edit_subject" label="Subject" variant="listbox" multiple placeholder="Select subjects...">
                @foreach ($subjectOptions as $subject)
                    <flux:select.option value="{{ $subject->value }}">{{ $subject->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="edit_role" label="Your role">
                <flux:select.option value="primary">Primary teacher / director</flux:select.option>
                <flux:select.option value="coteacher">Co-teacher / assistant director</flux:select.option>
            </flux:select>

            @unless ($isAdding)
                <flux:separator />

                <div>
                    <flux:button variant="ghost" wire:click="resetPassword" wire:confirm="Reset this student's password to their email address?">
                        Reset password
                    </flux:button>

                    @if ($passwordResetNotice)
                        <flux:callout variant="success" icon="check-circle" class="mt-2">
                            <flux:callout.text>{{ $passwordResetNotice }}</flux:callout.text>
                        </flux:callout>
                    @endif
                </div>
            @endunless

            @if ($errors->any())
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>This form has not been saved. Please fix the highlighted fields above before saving.</flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex items-center gap-2">
                <flux:spacer />

                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ $isAdding ? 'Add student' : 'Save' }}</flux:button>
            </div>
            @endif
        </form>
    </flux:modal>

    {{-- Spotlight tour, same approach as Events\Index's (resources/views/livewire/events/index.blade.php).
         Only covers the roster list itself, not the add/edit modal's several
         conditional forms (add / edit / claim-existing / attach-existing) —
         those are covered in prose in the written Teacher guide instead. --}}
    <button type="button" id="tour-dismiss-trigger" wire:click="dismissOrientation" class="hidden" aria-hidden="true" tabindex="-1"></button>

    <div id="tour-scrim" class="hidden fixed inset-0 z-[59]"></div>
    <div id="tour-cutout" class="hidden fixed z-[60] rounded-lg pointer-events-none transition-[top,left,width,height] duration-300 ease-out"></div>
    <div
        id="tour-card"
        class="hidden fixed z-[61] w-72 max-w-[calc(100vw-2rem)] bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg shadow-xl p-4 transition-[top,left] duration-300 ease-out"
        role="dialog" aria-modal="true" aria-labelledby="tour-title" aria-describedby="tour-body"
    >
        <div class="h-1 rounded-full bg-zinc-100 dark:bg-zinc-700 overflow-hidden mb-3">
            <div id="tour-progress" class="h-full bg-orange-600 dark:bg-orange-400 rounded-full transition-[width] duration-200"></div>
        </div>
        <div id="tour-stepcount" class="text-[11px] font-semibold uppercase tracking-wide text-orange-600 dark:text-orange-400 mb-1"></div>
        <h3 id="tour-title" class="text-sm font-semibold text-zinc-800 dark:text-zinc-100 mb-1"></h3>
        <p id="tour-body" class="text-sm text-zinc-500 dark:text-zinc-400 mb-3"></p>
        <div class="flex items-center justify-between gap-2">
            <button type="button" id="tour-skip" class="text-sm text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200">Skip tour</button>
            <div class="flex gap-2">
                <button type="button" id="tour-prev" class="text-sm font-medium px-3 py-1.5 rounded-md border border-zinc-200 dark:border-zinc-700 text-zinc-700 dark:text-zinc-200 hover:bg-zinc-50 dark:hover:bg-zinc-700 disabled:opacity-40">Back</button>
                <button type="button" id="tour-next" class="text-sm font-medium px-3 py-1.5 rounded-md border border-orange-600 bg-orange-600 text-white hover:brightness-110 dark:border-orange-400 dark:bg-orange-400 dark:text-zinc-900">Next</button>
            </div>
        </div>
    </div>

    <style>
        #tour-cutout { box-shadow: 0 0 0 9999px rgba(15, 13, 12, 0.6); }
        :root[data-theme="dark"] #tour-cutout { box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.72); }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) #tour-cutout { box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.72); }
        }
        #tour-cutout::after {
            content: "";
            position: absolute;
            inset: -4px;
            border-radius: 11px;
            border: 2px solid rgb(234 88 12);
            box-shadow: 0 0 0 4px rgba(234, 88, 12, 0.5);
            animation: tour-pulse 1.8s ease-in-out infinite;
        }
        @keyframes tour-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.45; }
        }
        @media (prefers-reduced-motion: reduce) {
            #tour-cutout, #tour-card { transition: none !important; }
            #tour-cutout::after { animation: none !important; }
        }
    </style>

    <script>
        (function () {
            var steps = [
                { ids: ['tour-search'], title: 'Search', body: 'Find one student by name without scrolling the full roster.' },
                { ids: ['tour-school-filter'], title: 'School filter', body: 'If you teach at more than one school or studio, narrow the roster down to just one.' },
                { ids: ['tour-add-student'], title: 'Add student', body: "Add a brand-new student, or link an existing one already in the system — TDR2027 will catch likely duplicates for you either way." },
                { ids: ['tour-roster-desktop', 'tour-roster-mobile'], title: 'Your roster', body: 'Every student currently linked to you, across every school.' },
                { ids: ['tour-status-desktop', 'tour-status-mobile'], title: 'Status', body: 'Pending means a claim request is waiting on approval from another teacher. Inactive students are hidden from registration until reactivated.' },
                { ids: ['tour-actions-desktop', 'tour-actions-mobile'], title: 'Manage a student', body: 'Edit their profile, deactivate them, or remove them from your roster entirely.' }
            ];

            var activeSteps = [];
            var current = -1;
            var running = false;
            var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            var raf = null;

            var scrim = document.getElementById('tour-scrim');
            var cutout = document.getElementById('tour-cutout');
            var card = document.getElementById('tour-card');
            var startBtn = document.getElementById('tour-start');
            var dismissTrigger = document.getElementById('tour-dismiss-trigger');
            var prevBtn = document.getElementById('tour-prev');
            var nextBtn = document.getElementById('tour-next');
            var skipBtn = document.getElementById('tour-skip');
            var titleEl = document.getElementById('tour-title');
            var bodyEl = document.getElementById('tour-body');
            var stepCountEl = document.getElementById('tour-stepcount');
            var progressEl = document.getElementById('tour-progress');

            if (!startBtn || !scrim || !cutout || !card) return;

            function resolveEl(ids) {
                for (var i = 0; i < ids.length; i++) {
                    var el = document.getElementById(ids[i]);
                    if (el && el.offsetParent !== null) return el;
                }
                return null;
            }

            function start() {
                activeSteps = steps.filter(function (s) { return resolveEl(s.ids) !== null; });
                if (activeSteps.length === 0) return;

                running = true;
                current = 0;
                scrim.classList.remove('hidden');
                cutout.classList.remove('hidden');
                card.classList.remove('hidden');
                document.addEventListener('keydown', onKeydown);
                window.addEventListener('resize', onReposition);
                window.addEventListener('scroll', onReposition, true);
                render();
            }

            function end() {
                running = false;
                scrim.classList.add('hidden');
                cutout.classList.add('hidden');
                card.classList.add('hidden');
                document.removeEventListener('keydown', onKeydown);
                window.removeEventListener('resize', onReposition);
                window.removeEventListener('scroll', onReposition, true);
                if (dismissTrigger) dismissTrigger.click();
                startBtn.focus();
            }

            function go(delta) {
                var target = current + delta;
                if (target < 0) return;
                if (target >= activeSteps.length) { end(); return; }
                current = target;
                render();
            }

            function render() {
                var step = activeSteps[current];
                var el = resolveEl(step.ids);
                if (!el) { go(1); return; }

                titleEl.textContent = step.title;
                bodyEl.textContent = step.body;
                stepCountEl.textContent = 'Step ' + (current + 1) + ' of ' + activeSteps.length;
                progressEl.style.width = (((current + 1) / activeSteps.length) * 100) + '%';
                prevBtn.disabled = current === 0;
                nextBtn.textContent = current === activeSteps.length - 1 ? 'Finish' : 'Next';

                el.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });

                window.setTimeout(function () { position(el); }, reduceMotion ? 0 : 260);
                nextBtn.focus();
            }

            function position(el) {
                var pad = 6;
                var r = el.getBoundingClientRect();

                cutout.style.top = (r.top - pad) + 'px';
                cutout.style.left = (r.left - pad) + 'px';
                cutout.style.width = (r.width + pad * 2) + 'px';
                cutout.style.height = (r.height + pad * 2) + 'px';

                var cardW = card.offsetWidth || 288;
                var cardH = card.offsetHeight || 160;
                var margin = 14;
                var vw = window.innerWidth;
                var vh = window.innerHeight;

                var top = r.bottom + margin;
                if (top + cardH > vh) {
                    top = r.top - cardH - margin;
                    if (top < 8) top = Math.max(8, Math.min(vh - cardH - 8, r.top));
                }

                var left = r.left;
                if (left + cardW > vw - 8) left = vw - cardW - 8;
                if (left < 8) left = 8;

                card.style.top = top + 'px';
                card.style.left = left + 'px';
            }

            function onReposition() {
                if (!running) return;
                if (raf) cancelAnimationFrame(raf);
                raf = requestAnimationFrame(function () {
                    var el = resolveEl(activeSteps[current].ids);
                    if (el) position(el);
                });
            }

            function onKeydown(e) {
                if (e.key === 'Escape') { end(); return; }
                if (e.key === 'ArrowRight' || e.key === 'Enter') { go(1); return; }
                if (e.key === 'ArrowLeft') { go(-1); return; }
            }

            startBtn.addEventListener('click', start);
            nextBtn.addEventListener('click', function () { go(1); });
            prevBtn.addEventListener('click', function () { go(-1); });
            skipBtn.addEventListener('click', end);

            if (startBtn.dataset.autoStart === '1') start();
        })();
    </script>
</div>
