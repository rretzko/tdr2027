<div class="flex flex-col gap-6">
    <flux:subheading>Tell us about your role at {{ $currentSchool?->name }}</flux:subheading>

    <flux:radio.group wire:model="role" label="Your role">
        <flux:radio value="primary" label="Primary teacher / director" />
        <flux:radio value="coteacher" label="Co-teacher / assistant director" />
    </flux:radio.group>

    <flux:checkbox wire:model.live="isReplacingTeacher" label="I'm replacing a teacher who is no longer at this school" />

    @if ($isReplacingTeacher)
        <flux:input wire:model="replacing_teacher_name" label="Name of the teacher you're replacing" required />
    @endif

    <flux:checkbox.group wire:model="subjects" label="Subjects you teach here">
        @foreach ($subjectOptions as $subject)
            <flux:checkbox value="{{ $subject->value }}" label="{{ $subject->label() }}" />
        @endforeach
    </flux:checkbox.group>

    <div class="flex items-center gap-4">
        <flux:button variant="ghost" wire:click="back">Back</flux:button>
        <flux:button variant="primary" wire:click="saveRoleAndSubjects">Continue</flux:button>
    </div>
</div>
