<div class="flex flex-col gap-6">
    <flux:subheading>Are you part of any sponsoring organizations?</flux:subheading>
    <flux:text size="sm" class="text-zinc-500">Optional — skip this if none apply to you.</flux:text>

    <div class="flex flex-col gap-4">
        @foreach ($organizationTree as $node)
            @include('livewire.onboarding.steps.organization-node', ['node' => $node, 'depth' => 0])
        @endforeach
    </div>

    <div class="flex items-center gap-4">
        <flux:button variant="ghost" wire:click="back">Back</flux:button>
        <flux:button variant="primary" wire:click="saveOrganizations">Continue</flux:button>
    </div>
</div>
