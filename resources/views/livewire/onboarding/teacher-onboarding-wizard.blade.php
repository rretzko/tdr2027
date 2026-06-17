<div class="mx-auto flex max-w-2xl flex-col gap-6">
    <div class="flex flex-col gap-2">
        <flux:heading size="xl">Let's get your account set up</flux:heading>
        <flux:subheading>Step {{ $step }} of 6</flux:subheading>
        <flux:progress value="{{ $step }}" min="1" max="6" />
    </div>

    @if ($step === 1)
        @include('livewire.onboarding.steps.step-1-school')
    @elseif ($step === 2)
        @include('livewire.onboarding.steps.step-2-role')
    @elseif ($step === 3)
        @include('livewire.onboarding.steps.step-3-students')
    @elseif ($step === 4)
        @include('livewire.onboarding.steps.step-4-organizations')
    @elseif ($step === 5)
        @include('livewire.onboarding.steps.step-5-events')
    @elseif ($step === 6)
        @include('livewire.onboarding.steps.step-6-overview')
    @endif
</div>
