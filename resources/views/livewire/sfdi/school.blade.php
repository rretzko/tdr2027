<div class="flex flex-col gap-6">
    <flux:heading size="xl">School</flux:heading>

    @if ($currentSchool)
        <flux:callout icon="building-library">
            <flux:callout.text>
                Your active school is <strong>{{ $currentSchool->name }}</strong>.
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($selectedSchool === null)
        <flux:card class="flex flex-col gap-4">
            <flux:subheading>{{ $currentSchool ? 'Switch to a different school' : 'Which school do you attend?' }}</flux:subheading>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="geostate_id" label="State">
                    <flux:select.option value="">Select a state...</flux:select.option>
                    @foreach (\App\Models\Geostate::orderBy('name')->get() as $geostate)
                        <flux:select.option value="{{ $geostate->id }}">{{ $geostate->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input
                    wire:model.live.blur="zip_code"
                    label="Zip code (optional)"
                    placeholder="e.g. 08901"
                    inputmode="numeric"
                    maxlength="5"
                />
            </div>

            <flux:input wire:model.live.debounce.300ms="school_search" label="School name" placeholder="Start typing to search..." />

            @if ($schoolSuggestions->isNotEmpty())
                <div class="flex flex-col gap-2">
                    @foreach ($schoolSuggestions as $match)
                        <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div>
                                <flux:text class="font-medium">{{ $match['school']->name }}</flux:text>
                                <flux:text size="sm" class="text-zinc-500">{{ $match['school']->city }}, {{ $match['school']->zip_code }}</flux:text>
                            </div>
                            <flux:button size="sm" wire:click="selectSchool({{ $match['school']->id }})">This is my school</flux:button>
                        </div>
                    @endforeach
                </div>
            @elseif ($school_search !== '' || $zip_code !== '')
                <flux:text class="text-zinc-500">
                    No matching schools found. If your school isn't listed yet, ask your teacher to add it via TheDirectorsRoom.com.
                </flux:text>
            @endif
        </flux:card>
    @else
        <flux:card class="flex flex-col gap-4">
            <div class="flex items-center justify-between">
                <flux:subheading>Joining {{ $selectedSchool->name }}</flux:subheading>
                <flux:button size="sm" variant="ghost" wire:click="changeSchool">Choose a different school</flux:button>
            </div>

            <flux:select wire:model="grade" label="Grade">
                <flux:select.option value="">Select a grade...</flux:select.option>
                @foreach ($gradeOptions as $gradeOption)
                    <flux:select.option value="{{ $gradeOption }}">Grade {{ $gradeOption }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="grade" />

            <flux:separator />

            <flux:subheading>Your teacher(s) at this school</flux:subheading>

            @if ($availableTeachers->isEmpty())
                <flux:text class="text-zinc-500">
                    No verified teachers found at this school yet. Ask your teacher to complete their TheDirectorsRoom.com setup first.
                </flux:text>
            @else
                <div class="flex flex-col gap-4">
                    @foreach ($availableTeachers as $teacher)
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <flux:checkbox.group wire:model="teacherSubjects.{{ $teacher->id }}" label="{{ $teacher->user->first_name }} {{ $teacher->user->last_name }}">
                                @foreach ($subjectOptions as $subject)
                                    <flux:checkbox value="{{ $subject->value }}" label="{{ $subject->label() }}" />
                                @endforeach
                            </flux:checkbox.group>
                        </div>
                    @endforeach
                </div>
            @endif
            <flux:error name="teacherSubjects" />

            <div class="flex justify-end">
                <flux:button variant="primary" wire:click="join">Join School</flux:button>
            </div>
        </flux:card>
    @endif
</div>
