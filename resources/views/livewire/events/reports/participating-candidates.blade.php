<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.reports', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Reports</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Participating Candidates</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">Participating Candidates</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — registered candidates</flux:text>
        </div>

        <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('events.versions.reports.participating-candidates.pdf', ['version' => $version, 'search' => $search, 'schoolFilter' => $schoolFilter, 'gradeFilter' => $gradeFilter, 'voicePartFilter' => $voicePartFilter])" target="_blank">
            Export PDF
        </flux:button>
    </div>

    <div class="flex flex-col sm:flex-row gap-3 mb-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by school, teacher, or candidate..." icon="magnifying-glass" class="sm:max-w-sm" />
        <flux:select wire:model.live="schoolFilter" placeholder="All schools" class="sm:max-w-2xs">
            <flux:select.option value="">All schools</flux:select.option>
            @foreach ($schoolOptions as $school)
                <flux:select.option value="{{ $school }}">{{ $school }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="gradeFilter" placeholder="All grades" class="sm:max-w-2xs">
            <flux:select.option value="">All grades</flux:select.option>
            @foreach ($gradeOptions as $grade)
                <flux:select.option value="{{ $grade }}">Grade {{ $grade }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="voicePartFilter" placeholder="All voice parts" class="sm:max-w-2xs">
            <flux:select.option value="">All voice parts</flux:select.option>
            @foreach ($availableVoiceParts as $voicePart)
                <flux:select.option value="{{ $voicePart->id }}">{{ $voicePart->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($schoolGroups->isEmpty())
        <flux:callout variant="info" icon="magnifying-glass">
            <flux:callout.text>
                @if ($search !== '' || $schoolFilter !== '' || $gradeFilter !== '' || $voicePartFilter !== '')
                    No candidates match your search or filter.
                @else
                    No candidates are registered for this Version yet.
                @endif
            </flux:callout.text>
        </flux:callout>
    @else
        {{-- Cards below lg:, table at lg:+ --}}
        <div class="lg:hidden space-y-6">
            @foreach ($schoolGroups as $schoolGroup)
                <div>
                    <flux:heading size="lg">{{ $schoolGroup['school']->name ?? '—' }}</flux:heading>

                    <div class="mt-3 space-y-4">
                        @foreach ($schoolGroup['teacherGroups'] as $teacherGroup)
                            <div>
                                <div class="mb-2">
                                    <flux:text class="font-medium">{{ $teacherGroup['teacher']->user->name }}</flux:text>
                                    <x-reports.teacher-contact-lines :teacher="$teacherGroup['teacher']" />
                                </div>

                                <div class="space-y-3">
                                    @foreach ($teacherGroup['candidates'] as $row)
                                        @php $candidate = $row['candidate']; @endphp
                                        <flux:card size="sm">
                                            <div class="flex items-start justify-between gap-3 mb-2">
                                                <flux:heading size="base">{{ $candidate->student->user->name }}</flux:heading>
                                                <flux:badge color="zinc" size="sm">{{ $candidate->voicePart->name }}</flux:badge>
                                            </div>
                                            <div class="text-sm text-zinc-500 mb-3">Grade {{ $row['grade'] ?? '—' }}</div>
                                            <div class="flex items-center gap-2">
                                                <flux:modal.trigger name="candidate-edit-form">
                                                    <flux:button size="sm" variant="outline" wire:click="edit({{ $candidate->id }})">Edit</flux:button>
                                                </flux:modal.trigger>
                                                <flux:spacer />
                                                <flux:dropdown>
                                                    <flux:button size="sm" variant="danger">Remove</flux:button>
                                                    <flux:menu>
                                                        <flux:menu.item wire:click="remove({{ $candidate->id }}, 'withdrew')" wire:confirm="Mark {{ $candidate->student->user->name }} as withdrew? This cannot be undone.">
                                                            Candidate withdrew
                                                        </flux:menu.item>
                                                        <flux:menu.item wire:click="remove({{ $candidate->id }}, 'teacher_withdrawn')" wire:confirm="Mark {{ $candidate->student->user->name }} as teacher-withdrawn? This cannot be undone.">
                                                            Teacher withdrew candidate
                                                        </flux:menu.item>
                                                    </flux:menu>
                                                </flux:dropdown>
                                            </div>
                                        </flux:card>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="hidden lg:block">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column sortable :sorted="true" :direction="$sortDirection" wire:click="toggleSort">Candidate</flux:table.column>
                    <flux:table.column>Grade</flux:table.column>
                    <flux:table.column>Voice Part</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($schoolGroups as $schoolGroup)
                        <flux:table.row :key="'school-'.($schoolGroup['school']->id ?? 'none')">
                            <flux:table.cell colspan="4">
                                {{-- The cell's own padding is zeroed on this colspan cell (Flux's
                                     first:ps-0/last:pe-0 rules — it's both first and last child),
                                     so -my-3 bleeds this div to fill the full row height for the
                                     shaded band; px-3/py-3 restore the visual padding on the div. --}}
                                <div class="-my-3 bg-zinc-100 px-3 py-3 font-semibold text-zinc-800 uppercase dark:bg-zinc-700 dark:text-zinc-100">
                                    {{ $schoolGroup['school']->name ?? '—' }}
                                </div>
                            </flux:table.cell>
                        </flux:table.row>

                        @foreach ($schoolGroup['teacherGroups'] as $teacherGroup)
                            <flux:table.row :key="'teacher-'.$teacherGroup['teacher']->id" class="{{ $loop->first ? '[&>td]:border-t-0' : '' }}">
                                <flux:table.cell colspan="4">
                                    <div class="-my-3 bg-zinc-100 py-3 pr-3 pl-9 dark:bg-zinc-700">
                                        <div class="font-medium">{{ $teacherGroup['teacher']->user->name }}</div>
                                        <x-reports.teacher-contact-lines :teacher="$teacherGroup['teacher']" class="mt-0.5" />
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>

                            @foreach ($teacherGroup['candidates'] as $row)
                                @php $candidate = $row['candidate']; @endphp
                                <flux:table.row :key="$candidate->id">
                                    <flux:table.cell>
                                        <div class="pl-10 font-medium">{{ $candidate->student->user->name }}</div>
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $row['grade'] ?? '—' }}</flux:table.cell>
                                    <flux:table.cell><flux:badge color="zinc" size="sm">{{ $candidate->voicePart->name }}</flux:badge></flux:table.cell>
                                    <flux:table.cell>
                                        <div class="flex items-center gap-2">
                                            <flux:modal.trigger name="candidate-edit-form">
                                                <flux:button size="sm" variant="outline" wire:click="edit({{ $candidate->id }})">Edit</flux:button>
                                            </flux:modal.trigger>
                                            <flux:dropdown>
                                                <flux:button size="sm" variant="danger">Remove</flux:button>
                                                <flux:menu>
                                                    <flux:menu.item wire:click="remove({{ $candidate->id }}, 'withdrew')" wire:confirm="Mark {{ $candidate->student->user->name }} as withdrew? This cannot be undone.">
                                                        Candidate withdrew
                                                    </flux:menu.item>
                                                    <flux:menu.item wire:click="remove({{ $candidate->id }}, 'teacher_withdrawn')" wire:confirm="Mark {{ $candidate->student->user->name }} as teacher-withdrawn? This cannot be undone.">
                                                        Teacher withdrew candidate
                                                    </flux:menu.item>
                                                </flux:menu>
                                            </flux:dropdown>
                                        </div>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        @endforeach
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:modal name="candidate-edit-form" class="md:w-[32rem]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">Edit candidate</flux:heading>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <flux:input wire:model="edit_first_name" label="First name" />
                <flux:input wire:model="edit_middle_name" label="Middle name" />
                <flux:input wire:model="edit_last_name" label="Last name" />
            </div>

            <flux:select wire:model="edit_voice_part_id" label="Voice Part" placeholder="Select a voice part...">
                @foreach ($availableVoiceParts as $voicePart)
                    <flux:select.option value="{{ $voicePart->id }}">{{ $voicePart->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:input wire:model="edit_home_phone" label="Home phone" type="tel" />
                <flux:input wire:model="edit_cell_phone" label="Cell phone" type="tel" />
            </div>

            <flux:select wire:model="edit_emergency_contact_id" label="Emergency Contact" placeholder="Select an emergency contact...">
                @foreach ($editEmergencyContactOptions as $contact)
                    <flux:select.option value="{{ $contact['id'] }}">{{ $contact['name'] }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($errors->any())
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>Please correct the errors above before saving.</flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
