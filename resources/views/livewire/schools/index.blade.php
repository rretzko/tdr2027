<div>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <flux:heading size="xl">Schools</flux:heading>

        <div class="flex items-center gap-3">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by name..." icon="magnifying-glass" class="sm:max-w-xs" />

            <flux:modal.trigger name="edit-school">
                <flux:button variant="primary" icon="plus" wire:click="add">
                    Add school
                </flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    @error('remove')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    @if (session('status') === 'no-active-school')
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-4">
            <flux:callout.text>Add or activate a school here before you can access Students or Events.</flux:callout.text>
        </flux:callout>
    @endif

    {{-- Cards below lg:, full table at lg: and up — 7 columns don't fit at md: once
         the persistent sidebar appears (see Students for the table that surfaced this). --}}
    <div class="lg:hidden space-y-3">
        @forelse ($schools as $school)
            <flux:card size="sm">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <flux:heading size="base" class="truncate">{{ $school->name }}</flux:heading>

                        <div class="mt-0.5 flex items-center gap-2">
                            @if ($school->pivot->school_email)
                                <flux:text size="sm" class="min-w-0 truncate text-zinc-500">{{ $school->pivot->school_email }}</flux:text>

                                @if ($school->pivot->verified_at)
                                    <flux:badge color="green" size="sm" class="shrink-0">Verified</flux:badge>
                                @else
                                    <flux:badge color="amber" size="sm" class="shrink-0">Pending</flux:badge>
                                @endif
                            @else
                                <flux:text size="sm" class="truncate italic text-zinc-400">No school email found</flux:text>
                            @endif
                        </div>

                        <flux:text size="sm" class="text-zinc-500">{{ $school->city }}</flux:text>
                    </div>

                    @if ($this->isPending($school->pivot))
                        <flux:badge color="amber" size="sm">Pending</flux:badge>
                    @elseif ($school->pivot->is_active)
                        <flux:badge color="green" size="sm">Active</flux:badge>
                    @else
                        <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                    @endif
                </div>

                <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                    <div>
                        <dt class="text-zinc-400">Type</dt>
                        <dd>{{ $school->type->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-400">County</dt>
                        <dd>{{ $school->county?->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-400">State</dt>
                        <dd>{{ $school->geostate?->abbr }}</dd>
                    </div>
                </dl>

                <div class="mt-4 grid grid-cols-3 gap-2">
                    <flux:modal.trigger name="edit-school">
                        <flux:button size="sm" variant="outline" class="w-full" wire:click="edit({{ $school->id }})">
                            Edit
                        </flux:button>
                    </flux:modal.trigger>

                    @unless ($this->isPending($school->pivot))
                        @if ($school->pivot->is_active)
                            <flux:button size="sm" variant="outline" wire:click="deactivate({{ $school->id }})">
                                Deactivate
                            </flux:button>
                        @else
                            <flux:button size="sm" variant="outline" wire:click="activate({{ $school->id }})">
                                Activate
                            </flux:button>
                        @endif
                    @endunless

                    <flux:button size="sm" variant="danger" wire:click="remove({{ $school->id }})" wire:confirm="Remove {{ $school->name }}? Only do this if it was linked by mistake — if you've had legitimate history there, mark it inactive instead. This cannot be undone.">
                        Remove
                    </flux:button>
                </div>
            </flux:card>
        @empty
            <flux:card size="sm" class="text-center text-zinc-500">
                No schools found.
            </flux:card>
        @endforelse

        <flux:pagination :paginator="$schools" />
    </div>

    <div class="hidden lg:block">
        <flux:table :paginate="$schools">
            <flux:table.columns>
                <flux:table.column sortable :sorted="$sortColumn === 'name'" :direction="$sortDirection" wire:click="sortBy('name')">
                    Name
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortColumn === 'type'" :direction="$sortDirection" wire:click="sortBy('type')">
                    Type
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortColumn === 'city'" :direction="$sortDirection" wire:click="sortBy('city')">
                    City
                </flux:table.column>
                <flux:table.column>County</flux:table.column>
                <flux:table.column>State</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column align="center">Actions</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($schools as $school)
                    <flux:table.row :key="$school->id">
                        <flux:table.cell>
                            <div class="max-w-64 truncate">{{ $school->name }}</div>

                            <div class="mt-0.5 flex items-center gap-2">
                                @if ($school->pivot->school_email)
                                    <flux:text size="sm" class="min-w-0 truncate text-zinc-500">{{ $school->pivot->school_email }}</flux:text>

                                    @if ($school->pivot->verified_at)
                                        <flux:badge color="green" size="sm" class="shrink-0">Verified</flux:badge>
                                    @else
                                        <flux:badge color="amber" size="sm" class="shrink-0">Pending</flux:badge>
                                    @endif
                                @else
                                    <flux:text size="sm" class="truncate italic text-zinc-400">No school email found</flux:text>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $school->type->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $school->city }}</flux:table.cell>
                        <flux:table.cell>{{ $school->county?->name }}</flux:table.cell>
                        <flux:table.cell>{{ $school->geostate?->abbr }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($this->isPending($school->pivot))
                                <flux:badge color="amber" size="sm">Pending</flux:badge>
                            @elseif ($school->pivot->is_active)
                                <flux:badge color="green" size="sm">Active</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center justify-center gap-1">
                                <flux:modal.trigger name="edit-school">
                                    <flux:button size="sm" variant="ghost" icon="pencil" aria-label="Edit school" wire:click="edit({{ $school->id }})" />
                                </flux:modal.trigger>

                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" aria-label="School actions" />

                                    <flux:menu>
                                        @unless ($this->isPending($school->pivot))
                                            @if ($school->pivot->is_active)
                                                <flux:menu.item wire:click="deactivate({{ $school->id }})">
                                                    Deactivate
                                                </flux:menu.item>
                                            @else
                                                <flux:menu.item wire:click="activate({{ $school->id }})">
                                                    Activate
                                                </flux:menu.item>
                                            @endif
                                        @endunless
                                        <flux:menu.item variant="danger" wire:click="remove({{ $school->id }})" wire:confirm="Remove {{ $school->name }}? Only do this if it was linked by mistake — if you've had legitimate history there, mark it inactive instead. This cannot be undone.">
                                            Remove
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7" class="text-center text-zinc-500">
                            No schools found.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="edit-school" class="md:w-[32rem]">
        <form wire:submit="{{ $isAdding ? 'saveAdd' : 'saveEdit' }}" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $isAdding ? 'Add school' : 'Edit school' }}</flux:heading>
                <flux:subheading>
                    @if ($isAdding)
                        Link yourself to a new school or studio.
                    @else
                        Update your role there and the school's details.
                    @endif
                </flux:subheading>
            </div>

            <flux:select wire:model="edit_role" label="Your role">
                <flux:select.option value="primary">Primary teacher / director</flux:select.option>
                <flux:select.option value="coteacher">Co-teacher / assistant director</flux:select.option>
            </flux:select>

            <flux:checkbox wire:model.live="edit_is_replacing_teacher" label="I'm replacing a teacher who is no longer at this school" />

            @if ($edit_is_replacing_teacher)
                <flux:input wire:model="edit_replacing_teacher_name" label="Name of the teacher you're replacing" />
            @endif

            <div>
                <flux:input wire:model.live.debounce.400ms="edit_school_email" type="email" label="School email" description="Verifying this unlocks access to student data at this school." />

                @if ($this->schoolEmailDomainWarning())
                    <flux:callout variant="warning" icon="exclamation-triangle" class="mt-2">
                        <flux:callout.text>{{ $this->schoolEmailDomainWarning() }}</flux:callout.text>
                    </flux:callout>
                @endif
            </div>

            <flux:separator />

            <flux:input wire:model.live.debounce.300ms="edit_name" label="School name" />

            <flux:radio.group wire:model="edit_type" label="Type">
                <flux:radio value="school" label="School" />
                <flux:radio value="studio" label="Studio" />
            </flux:radio.group>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input wire:model="edit_city" label="City" />
                <flux:input wire:model.live.debounce.300ms="edit_zip_code" label="Zip code" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="edit_geostate_id" label="State">
                    <flux:select.option value="">Select a state...</flux:select.option>
                    @foreach ($geostates as $geostate)
                        <flux:select.option value="{{ $geostate->id }}">{{ $geostate->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="edit_county_id" label="County" placeholder="Select a county...">
                    @foreach ($editCounties as $county)
                        <flux:select.option value="{{ $county->id }}">{{ $county->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @if ($isAdding && $this->schoolSuggestions()->isNotEmpty())
                <div class="flex flex-col gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950/40">
                    <flux:text size="sm" class="font-medium">This looks like it may already exist:</flux:text>

                    @foreach ($this->schoolSuggestions() as $match)
                        <div class="flex items-center justify-between gap-3 rounded-md border border-zinc-200 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-800">
                            <div>
                                <flux:text class="font-medium">{{ $match['school']->name }}</flux:text>
                                <flux:text size="sm" class="text-zinc-500">{{ $match['school']->city }}, {{ $match['school']->zip_code }} &middot; {{ $match['school']->type->label() }}</flux:text>
                            </div>
                            <flux:button size="sm" wire:click="linkExistingSchool({{ $match['school']->id }})">This is my school</flux:button>
                        </div>
                    @endforeach

                    @unless ($confirmedNewSchool)
                        <flux:button size="sm" variant="ghost" wire:click="$set('confirmedNewSchool', true)">
                            None of these — add a new school anyway
                        </flux:button>
                    @endunless
                </div>
            @endif

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button
                    type="submit"
                    variant="primary"
                    :disabled="$isAdding && ! $confirmedNewSchool && $this->schoolSuggestions()->isNotEmpty()"
                >
                    {{ $isAdding ? 'Add school' : 'Save' }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
