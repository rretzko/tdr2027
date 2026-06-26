@php $organization = $node['organization']; @endphp

<div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" style="margin-left: {{ $depth * 1.5 }}rem">
    <flux:checkbox wire:model.live="selectedOrganizationIds" value="{{ $organization->id }}" label="{{ $organization->name }}{{ $organization->abbr ? ' ('.$organization->abbr.')' : '' }}" />
</div>

@foreach ($node['children'] as $child)
    @include('livewire.onboarding.steps.organization-node', ['node' => $child, 'depth' => $depth + 1])
@endforeach
