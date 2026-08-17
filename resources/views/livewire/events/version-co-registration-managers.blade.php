<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>{{ $version->name }}</span>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Co-Registration Managers</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">Co-Registration Managers</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — county-based registration authority</flux:text>
        </div>

        <flux:button
            size="sm"
            variant="primary"
            icon="plus"
            type="button"
            x-on:click="$wire.add().then(() => $dispatch('modal-show', { name: 'co-registration-manager-form' }))"
        >
            Assign Co-Registration Manager
        </flux:button>
    </div>

    @if ($version->counties->isEmpty())
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6">
            <flux:callout.text>This Version has no eligible counties configured yet, so there is nothing to assign a Co-Registration Manager responsibility for. Add counties under Configure &rarr; Requirements first.</flux:callout.text>
        </flux:callout>
    @endif

    @if ($managers->isEmpty())
        <flux:callout variant="info" icon="magnifying-glass">
            <flux:callout.text>No Co-Registration Managers have been assigned to this Version yet.</flux:callout.text>
        </flux:callout>
    @else
        {{-- Cards below lg:, table at lg:+ --}}
        <div class="lg:hidden space-y-3">
            @foreach ($managers as $userId => $rows)
                @php $user = $rows->first()->user; @endphp
                <flux:card size="sm">
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <div>
                            <flux:heading size="base">{{ $user->name }}</flux:heading>
                            <flux:text size="sm" class="text-zinc-500">{{ $user->email }}</flux:text>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-1 mb-3">
                        @foreach ($rows as $row)
                            <flux:badge color="teal" size="sm">{{ $row->county->name }}</flux:badge>
                        @endforeach
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:spacer />
                        <flux:button
                            size="sm"
                            variant="outline"
                            type="button"
                            x-on:click="$wire.editCounties({{ $userId }}).then(() => $dispatch('modal-show', { name: 'co-registration-manager-form' }))"
                        >
                            Edit Counties
                        </flux:button>
                        <flux:button size="sm" variant="danger" wire:click="remove({{ $userId }})" wire:confirm="Remove {{ $user->name }} as Co-Registration Manager?">
                            Remove
                        </flux:button>
                    </div>
                </flux:card>
            @endforeach
        </div>

        <div class="hidden lg:block">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Email</flux:table.column>
                    <flux:table.column>Counties</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($managers as $userId => $rows)
                        @php $user = $rows->first()->user; @endphp
                        <flux:table.row>
                            <flux:table.cell class="font-medium">{{ $user->name }}</flux:table.cell>
                            <flux:table.cell>{{ $user->email }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="flex flex-wrap gap-1 max-w-[320px]">
                                    @foreach ($rows as $row)
                                        <flux:badge color="teal" size="sm">{{ $row->county->name }}</flux:badge>
                                    @endforeach
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex justify-end gap-2">
                                    <flux:button
                                        size="sm"
                                        variant="outline"
                                        type="button"
                                        x-on:click="$wire.editCounties({{ $userId }}).then(() => $dispatch('modal-show', { name: 'co-registration-manager-form' }))"
                                    >
                                        Edit Counties
                                    </flux:button>
                                    <flux:button size="sm" variant="danger" wire:click="remove({{ $userId }})" wire:confirm="Remove {{ $user->name }} as Co-Registration Manager?">
                                        Remove
                                    </flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:modal name="co-registration-manager-form" class="md:w-[28rem]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingUserId === null ? 'Assign Co-Registration Manager' : 'Edit Counties' }}</flux:heading>
                <flux:subheading>{{ $version->name }}</flux:subheading>
            </div>

            @if ($editingUserId === null)
                <flux:field>
                    <flux:label>Name</flux:label>
                    <div class="flex items-start gap-2">
                        <div class="flex-1">
                            <flux:input
                                wire:model.live.debounce.300ms="search"
                                placeholder="Search by name..."
                                :disabled="$selectedUserId !== null"
                                autofocus
                            />
                            <flux:error name="selectedUserId" />
                        </div>
                        @if ($selectedUserId !== null)
                            <flux:button icon="x-mark" wire:click="clearSelection" type="button" />
                        @endif
                    </div>
                </flux:field>

                @if ($this->searchResults()->isNotEmpty())
                    <div class="flex flex-col gap-2">
                        @foreach ($this->searchResults() as $result)
                            <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                <div>
                                    <flux:text class="font-medium">{{ $result->name }}</flux:text>
                                    <flux:text size="sm" class="text-zinc-500">{{ $result->email }}</flux:text>
                                </div>
                                <flux:button size="sm" wire:click="selectUser({{ $result->id }})">Select</flux:button>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif

            <div>
                <flux:label>Counties</flux:label>
                <flux:description>Counties already assigned to another Co-Registration Manager on this Version aren't shown here.</flux:description>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 max-h-64 overflow-y-auto pr-1 mt-1">
                    @forelse ($availableCounties->whereIn('id', $assignableCountyIds) as $county)
                        <flux:checkbox wire:model="countyIds" value="{{ $county->id }}" label="{{ $county->name }}" />
                    @empty
                        <flux:text size="sm" class="text-zinc-400">No counties available to assign.</flux:text>
                    @endforelse
                </div>
                <flux:error name="countyIds" />
            </div>

            <div class="space-y-4 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:heading size="sm">Mail-To Address</flux:heading>
                <flux:description>Printed on the Estimate Form's Mail-To page for schools in this manager's assigned counties.</flux:description>

                <flux:field>
                    <flux:label>Recipient name</flux:label>
                    <flux:input wire:model="mailto_recipient_name" />
                    <flux:error name="mailto_recipient_name" />
                </flux:field>

                <flux:field>
                    <flux:label>Organization / school line</flux:label>
                    <flux:input wire:model="mailto_organization_line" />
                    <flux:error name="mailto_organization_line" />
                </flux:field>

                <flux:field>
                    <flux:label>Address line 1</flux:label>
                    <flux:input wire:model="mailto_address_line1" />
                    <flux:error name="mailto_address_line1" />
                </flux:field>

                <flux:field>
                    <flux:label>Address line 2</flux:label>
                    <flux:input wire:model="mailto_address_line2" />
                    <flux:error name="mailto_address_line2" />
                </flux:field>

                <flux:field>
                    <flux:label>City</flux:label>
                    <flux:input wire:model="mailto_city" />
                    <flux:error name="mailto_city" />
                </flux:field>

                <div class="grid grid-cols-2 gap-3">
                    <flux:field>
                        <flux:label>State</flux:label>
                        <flux:select wire:model="mailto_geostate_id">
                            <flux:select.option value="">— select —</flux:select.option>
                            @foreach ($geostates as $geostate)
                                <flux:select.option value="{{ $geostate->id }}">{{ $geostate->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="mailto_geostate_id" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Zip</flux:label>
                        <flux:input wire:model="mailto_zip" />
                        <flux:error name="mailto_zip" />
                    </flux:field>
                </div>
            </div>

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
                <flux:button type="submit" variant="primary" :disabled="$selectedUserId === null">
                    {{ $editingUserId === null ? 'Assign' : 'Save' }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
