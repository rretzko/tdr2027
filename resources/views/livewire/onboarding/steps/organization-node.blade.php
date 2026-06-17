@php $organization = $node['organization']; @endphp

<div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" style="margin-left: {{ $depth * 1.5 }}rem">
    <flux:checkbox wire:model.live="selectedOrganizationIds" value="{{ $organization->id }}" label="{{ $organization->name }}{{ $organization->abbr ? ' ('.$organization->abbr.')' : '' }}" />

    @if (in_array($organization->id, $selectedOrganizationIds))
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <flux:input wire:model="supervisorName.{{ $organization->id }}" placeholder="Your contact's name" label="Supervisor name (optional)" />
            <flux:input wire:model="supervisorEmail.{{ $organization->id }}" type="email" placeholder="Email" label="Supervisor email (optional)" />
            <flux:input wire:model="supervisorCellPhone.{{ $organization->id }}" type="tel" placeholder="Cell phone" label="Supervisor cell phone (optional)" />
        </div>
    @endif
</div>

@foreach ($node['children'] as $child)
    @include('livewire.onboarding.steps.organization-node', ['node' => $child, 'depth' => $depth + 1])
@endforeach
