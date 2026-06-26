@php
    $organization = $node['organization'];
    $rootOrg = $organization->membershipOrganization();
    $rootOrgId = $rootOrg->id;
    $isSelected = in_array($organization->id, $selectedOrganizationIds);
    $isRoot = $organization->parent_id === null;
@endphp

<div class="rounded-lg border border-zinc-200 dark:border-zinc-700" style="margin-left: {{ $depth * 1.5 }}rem">
    <div class="p-3">
        <flux:checkbox
            wire:model.live="selectedOrganizationIds"
            value="{{ $organization->id }}"
            label="{{ $organization->name }}{{ $organization->abbr ? ' ('.$organization->abbr.')' : '' }}"
        />
    </div>

    @if ($isSelected)
        @if ($isRoot)
            <div class="border-t border-zinc-100 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                <div class="ml-6 md:w-1/2">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <flux:input
                            wire:model="membershipNumber.{{ $rootOrgId }}"
                            label="Membership number"
                            placeholder="e.g. MBR-12345"
                        />
                        <flux:input
                            wire:model="membershipExpiresAt.{{ $rootOrgId }}"
                            type="date"
                            label="Expiration date"
                        />
                    </div>

                    <div class="mt-4">
                        <flux:label>Membership card image</flux:label>

                        @if (isset($existingMembershipCards[$rootOrgId]))
                            <div class="mb-3">
                                <img
                                    src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($existingMembershipCards[$rootOrgId]) }}"
                                    alt="Membership card"
                                    class="h-24 w-auto rounded-md border border-zinc-200 object-cover dark:border-zinc-700"
                                />
                            </div>
                        @endif

                        <input
                            type="file"
                            wire:model="membershipCards.{{ $rootOrgId }}"
                            accept="image/*"
                            class="block w-full text-sm text-zinc-600 file:mr-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-300"
                        />

                        <div wire:loading wire:target="membershipCards.{{ $rootOrgId }}">
                            <flux:text size="sm" class="mt-1 text-zinc-400">Uploading…</flux:text>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="border-t border-zinc-100 px-4 py-2 dark:border-zinc-700">
                <flux:text size="sm" class="text-zinc-400">
                    Uses your {{ $rootOrg->name }} membership
                </flux:text>
            </div>
        @endif
    @endif
</div>

@foreach ($node['children'] as $child)
    @include('livewire.organizations.organization-node', ['node' => $child, 'depth' => $depth + 1])
@endforeach
