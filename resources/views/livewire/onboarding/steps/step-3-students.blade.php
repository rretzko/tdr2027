<div class="flex flex-col gap-6">
    <flux:subheading>Build your roster at {{ $currentSchool?->name }}</flux:subheading>

    @if ($errors->any())
        <flux:callout color="red" icon="exclamation-triangle" heading="Please fix the following before continuing">
            <flux:callout.text>
                <ul class="list-disc pl-4">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($existingStudents->isNotEmpty())
        <div class="flex flex-col gap-3">
            <flux:text class="font-medium">Students already at this school</flux:text>

            @foreach ($existingStudents as $student)
                <div class="flex flex-col gap-1 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <div class="flex items-center justify-between gap-4">
                        <flux:checkbox wire:model="claimedStudentIds" value="{{ $student->id }}" label="{{ $student->user->first_name }} {{ $student->user->last_name }}" />

                        @if (count($subjects) > 1)
                            <flux:select wire:model="claimedStudentSubject.{{ $student->id }}" placeholder="Subject..." size="sm">
                                @foreach ($subjects as $subject)
                                    <flux:select.option value="{{ $subject }}">{{ \App\Enums\Subject::from($subject)->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif
                    </div>
                    <flux:error name="claimedStudentSubject.{{ $student->id }}" />
                </div>
            @endforeach
        </div>

        <flux:separator />
    @endif

    <div class="flex flex-col gap-3">
        <flux:text class="font-medium">Add new students</flux:text>

        @foreach ($newStudents as $index => $row)
            <div class="flex flex-col gap-1 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-5">
                    <flux:input wire:model="newStudents.{{ $index }}.first_name" placeholder="First name" />
                    <flux:input wire:model="newStudents.{{ $index }}.last_name" placeholder="Last name" />

                    <flux:select wire:model="newStudents.{{ $index }}.class_of" placeholder="Grade...">
                        @foreach ($classOfOptions as $option)
                            <flux:select.option value="{{ $option['class_of'] }}">{{ $option['label'] }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if (count($subjects) > 1)
                        <flux:select wire:model="newStudents.{{ $index }}.subject" placeholder="Subject...">
                            @foreach ($subjects as $subject)
                                <flux:select.option value="{{ $subject }}">{{ \App\Enums\Subject::from($subject)->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

                    <flux:button variant="ghost" size="sm" wire:click="removeNewStudentRow({{ $index }})">Remove</flux:button>
                </div>
                <flux:error name="newStudents.{{ $index }}.first_name" />
                <flux:error name="newStudents.{{ $index }}.last_name" />
                <flux:error name="newStudents.{{ $index }}.class_of" />
                <flux:error name="newStudents.{{ $index }}.subject" />
            </div>
        @endforeach

        <flux:button variant="ghost" wire:click="addNewStudentRow">+ Add a student</flux:button>
    </div>

    <div class="flex items-center gap-4">
        <flux:button variant="ghost" wire:click="back">Back</flux:button>
        <flux:button variant="primary" wire:click="saveStudents">Continue</flux:button>
    </div>
</div>
