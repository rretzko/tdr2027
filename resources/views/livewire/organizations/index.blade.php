<div>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <flux:heading size="xl">Organizations</flux:heading>
    </div>

    <flux:text class="mb-6 text-zinc-500">
        Select the organizations you belong to and optionally provide your supervisor's contact information.
    </flux:text>

    @if ($organizationTree === [])
        <flux:card size="sm" class="text-center text-zinc-500">
            No organizations are available yet.
        </flux:card>
    @else
        <div class="flex flex-col gap-4">
            @foreach ($organizationTree as $node)
                @include('livewire.organizations.organization-node', ['node' => $node, 'depth' => 0])
            @endforeach
        </div>

        <div class="mt-6">
            <flux:button variant="primary" wire:click="save">Save</flux:button>
        </div>
    @endif
</div>
