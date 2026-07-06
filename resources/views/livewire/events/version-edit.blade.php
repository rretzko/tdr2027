<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.index') }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Events</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>{{ $version->name }}</span>
    </div>

    <flux:heading size="xl" class="mb-1">{{ $version->name }}</flux:heading>
    <flux:text size="sm" class="text-zinc-500 mb-6">Version configuration</flux:text>

    <flux:tabs wire:model="activeTab">
        <flux:tab name="general">General</flux:tab>
        <flux:tab name="dates">Dates</flux:tab>
        <flux:tab name="fees">Fees</flux:tab>
        <flux:tab name="requirements">Requirements</flux:tab>
        <flux:tab name="roles">Roles</flux:tab>

        {{-- General --}}
        <flux:tab.panel name="general">
            <div class="mt-6 space-y-6 max-w-2xl">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <flux:field class="sm:col-span-2">
                        <flux:label>Version Name</flux:label>
                        <flux:input wire:model="name" />
                        <flux:error name="name" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Short Name</flux:label>
                        <flux:input wire:model="short_name" />
                        <flux:error name="short_name" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Senior Class Of</flux:label>
                        <flux:input wire:model="senior_class_of" type="number" min="2000" max="2100" />
                        <flux:error name="senior_class_of" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Status</flux:label>
                        <flux:select wire:model="status">
                            @foreach ($statuses as $s)
                                <flux:select.option value="{{ $s->value }}">{{ $s->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="status" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Application Type</flux:label>
                        <flux:select wire:model="application_type">
                            @foreach ($applicationTypes as $at)
                                <flux:select.option value="{{ $at->value }}">{{ $at->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="application_type" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Audition Type</flux:label>
                        <flux:select wire:model="audition_type">
                            @foreach ($auditionTypes as $at)
                                <flux:select.option value="{{ $at->value }}">{{ $at->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="audition_type" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Audition Slot (minutes)</flux:label>
                        <flux:input wire:model="audition_timeslot" type="number" min="5" max="120" />
                        <flux:description>Duration per candidate for in-person scheduling.</flux:description>
                        <flux:error name="audition_timeslot" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Upload Type</flux:label>
                        <flux:select wire:model="upload_type">
                            @foreach ($uploadTypes as $ut)
                                <flux:select.option value="{{ $ut->value }}">{{ $ut->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="upload_type" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Judge Count</flux:label>
                        <flux:input wire:model="judge_count" type="number" min="1" max="20" />
                        <flux:error name="judge_count" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Score Order</flux:label>
                        <flux:select wire:model="score_order">
                            @foreach ($scoreOrders as $so)
                                <flux:select.option value="{{ $so->value }}">{{ $so->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="score_order" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Pitch File Visibility</flux:label>
                        <flux:select wire:model="pitch_file_visibility">
                            @foreach ($pitchVisibilities as $pv)
                                <flux:select.option value="{{ $pv->value }}">{{ $pv->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="pitch_file_visibility" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Max Registrants</flux:label>
                        <flux:input wire:model="max_registrants" type="number" min="1" placeholder="No limit" />
                        <flux:error name="max_registrants" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Max Upper Voice Registrants</flux:label>
                        <flux:input wire:model="max_upper_voice_registrants" type="number" min="1" placeholder="No limit" />
                        <flux:error name="max_upper_voice_registrants" />
                    </flux:field>
                </div>

                <div>
                    <flux:heading size="base" class="mb-3">Optional Fields Collected at Registration</flux:heading>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        <flux:checkbox wire:model="birthday" label="Birthday" />
                        <flux:checkbox wire:model="emergency_contact_name" label="Emergency Contact Name" />
                        <flux:checkbox wire:model="emergency_contact_cell" label="Emergency Contact Cell" />
                        <flux:checkbox wire:model="emergency_contact_email" label="Emergency Contact Email" />
                        <flux:checkbox wire:model="height" label="Height" />
                        <flux:checkbox wire:model="home_address" label="Home Address" />
                        <flux:checkbox wire:model="shirt_size" label="Shirt Size" />
                        <flux:checkbox wire:model="teacher_cell" label="Teacher Cell" />
                        <flux:checkbox wire:model="release_confidential_results" label="Release Confidential Results" />
                    </div>
                </div>

                @if ($errors->any())
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.text>Please correct the errors above.</flux:callout.text>
                    </flux:callout>
                @endif

                <flux:button variant="primary" wire:click="saveGeneral">
                    Save General Settings
                </flux:button>
            </div>
        </flux:tab.panel>

        {{-- Dates --}}
        <flux:tab.panel name="dates">
            <div class="mt-6 space-y-6 max-w-2xl">
                @foreach ($dateTypes as $dateType)
                    @php $key = $dateType->value; @endphp
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                        <flux:heading size="sm" class="mb-3">{{ $dateType->label() }}</flux:heading>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>{{ $dateType->hasEndAt() ? 'Start' : 'Date' }}</flux:label>
                                <flux:input wire:model="date_start.{{ $key }}" type="datetime-local" />
                                <flux:error name="date_start.{{ $key }}" />
                            </flux:field>

                            @if ($dateType->hasEndAt())
                                <flux:field>
                                    <flux:label>End</flux:label>
                                    <flux:input wire:model="date_end.{{ $key }}" type="datetime-local" />
                                    <flux:error name="date_end.{{ $key }}" />
                                </flux:field>
                            @endif
                        </div>
                    </div>
                @endforeach

                @if ($errors->any())
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.text>Please correct the errors above.</flux:callout.text>
                    </flux:callout>
                @endif

                <flux:button variant="primary" wire:click="saveDates">
                    Save Dates
                </flux:button>
            </div>
        </flux:tab.panel>

        {{-- Fees --}}
        <flux:tab.panel name="fees">
            <div class="mt-6 space-y-4 max-w-md">
                <flux:callout variant="info" icon="information-circle">
                    <flux:callout.text>Enter amounts in dollars (e.g. 20.00). Zero means no charge.</flux:callout.text>
                </flux:callout>

                <flux:field>
                    <flux:label>Registration Fee</flux:label>
                    <flux:input wire:model="fee_registration" type="number" min="0" step="0.01" prefix="$" />
                    <flux:error name="fee_registration" />
                </flux:field>

                <flux:field>
                    <flux:label>On-Site Registration Fee</flux:label>
                    <flux:input wire:model="fee_on_site_registration" type="number" min="0" step="0.01" prefix="$" />
                    <flux:error name="fee_on_site_registration" />
                </flux:field>

                <flux:field>
                    <flux:label>Participation Fee</flux:label>
                    <flux:input wire:model="fee_participation" type="number" min="0" step="0.01" prefix="$" />
                    <flux:error name="fee_participation" />
                </flux:field>

                <flux:field>
                    <flux:label>E-Payment Surcharge</flux:label>
                    <flux:input wire:model="fee_epayment_surcharge" type="number" min="0" step="0.01" prefix="$" />
                    <flux:error name="fee_epayment_surcharge" />
                </flux:field>

                <flux:field>
                    <flux:label>Housing Fee</flux:label>
                    <flux:input wire:model="fee_housing" type="number" min="0" step="0.01" prefix="$" />
                    <flux:error name="fee_housing" />
                </flux:field>

                @if ($errors->any())
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.text>Please correct the errors above.</flux:callout.text>
                    </flux:callout>
                @endif

                <flux:button variant="primary" wire:click="saveFees">
                    Save Fees
                </flux:button>
            </div>
        </flux:tab.panel>

        {{-- Requirements --}}
        <flux:tab.panel name="requirements">
            <div class="mt-6 space-y-6 max-w-2xl">
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 space-y-4">
                    <flux:heading size="sm">Membership</flux:heading>

                    <flux:checkbox wire:model="membership_card" label="Membership card required" />

                    <flux:field>
                        <flux:label>Membership Valid Thru</flux:label>
                        <flux:input wire:model="membership_valid_thru" type="date" />
                        <flux:description>Leave blank if any valid membership is accepted.</flux:description>
                        <flux:error name="membership_valid_thru" />
                    </flux:field>
                </div>

                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 space-y-4">
                    <flux:heading size="sm">Eligible Counties</flux:heading>
                    <flux:description>Leave all unchecked to allow any county.</flux:description>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 max-h-64 overflow-y-auto pr-1">
                        @foreach ($counties as $county)
                            <flux:checkbox
                                wire:model="selected_county_ids"
                                value="{{ $county->id }}"
                                label="{{ $county->name }}"
                            />
                        @endforeach
                    </div>
                    <flux:error name="selected_county_ids" />
                </div>

                @if ($eventEnsembles->isNotEmpty())
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 space-y-4">
                        <div>
                            <flux:heading size="sm">Ensemble Fill Order</flux:heading>
                            <flux:description>Set the priority order for ensemble assignment when multiple ensembles share a candidate pool. Lower numbers fill first.</flux:description>
                        </div>

                        <div class="space-y-2">
                            @foreach ($eventEnsembles as $ensemble)
                                <div class="flex items-center gap-3">
                                    <flux:input
                                        wire:model="ensemble_order.{{ $ensemble->id }}"
                                        type="number" min="1" max="99"
                                        class="w-20"
                                    />
                                    <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $ensemble->name }}</span>
                                    @if ($ensemble->abbreviation)
                                        <flux:badge color="zinc" size="sm">{{ $ensemble->abbreviation }}</flux:badge>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        <flux:button size="sm" wire:click="saveEnsembleOrder">
                            Save Ensemble Order
                        </flux:button>
                    </div>
                @endif

                @if ($errors->any())
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.text>Please correct the errors above.</flux:callout.text>
                    </flux:callout>
                @endif

                <flux:button variant="primary" wire:click="saveRequirements">
                    Save Requirements
                </flux:button>
            </div>
        </flux:tab.panel>

        {{-- Roles --}}
        <flux:tab.panel name="roles">
            <div class="mt-6 space-y-6 max-w-2xl">
                @foreach ($assignableRoles as $role)
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                        <flux:heading size="sm" class="mb-3">{{ $role }}</flux:heading>

                        @php $users = $roleAssignments->get($role); @endphp
                        @if ($users->isEmpty())
                            <flux:text size="sm" class="text-zinc-400">No one assigned.</flux:text>
                        @else
                            <div class="space-y-2">
                                @foreach ($users as $user)
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-sm">{{ $user->name }} <span class="text-zinc-400">({{ $user->email }})</span></span>
                                        @if ($canManageRoles)
                                            <flux:button
                                                size="sm" variant="ghost" icon="x-mark"
                                                wire:click="revokeRole({{ $user->id }}, '{{ $role }}')"
                                                wire:confirm="Remove {{ $user->name }} as {{ $role }}?"
                                            >
                                                Remove
                                            </flux:button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach

                @if ($canManageRoles)
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 space-y-4">
                        <flux:heading size="sm">Assign a Role</flux:heading>

                        <flux:field>
                            <flux:label>Email</flux:label>
                            <flux:input wire:model="assign_email" placeholder="person@example.com" />
                            <flux:error name="assign_email" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Role</flux:label>
                            <flux:select wire:model="assign_role">
                                <flux:select.option value="">— select role —</flux:select.option>
                                @foreach ($assignableRoles as $role)
                                    <flux:select.option value="{{ $role }}">{{ $role }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:error name="assign_role" />
                        </flux:field>

                        <flux:button variant="primary" wire:click="assignRole">
                            Assign Role
                        </flux:button>
                    </div>
                @endif
            </div>
        </flux:tab.panel>
    </flux:tabs>
</div>
