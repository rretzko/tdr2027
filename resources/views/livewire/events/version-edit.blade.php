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

    <flux:tab.group>
        <flux:tabs wire:model="activeTab">
            <flux:tab name="general">General</flux:tab>
            <flux:tab name="dates">Dates</flux:tab>
            <flux:tab name="fees">Fees</flux:tab>
            <flux:tab name="requirements">Requirements</flux:tab>
            <flux:tab name="obligations">Obligations</flux:tab>
            <flux:tab name="roles">Roles</flux:tab>
        </flux:tabs>

        {{-- General --}}
        <flux:tab.panel name="general">
            <div class="mt-6 space-y-6 max-w-2xl">
                <div class="grid grid-cols-1 gap-4">
                    <flux:field>
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
                        <flux:description>Type of application that the student will use.</flux:description>
                        <flux:error name="application_type" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Audition Type</flux:label>
                        <flux:select wire:model.live="audition_type">
                            @foreach ($auditionTypes as $at)
                                <flux:select.option value="{{ $at->value }}">{{ $at->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="audition_type" />
                    </flux:field>

                    @if ($audition_type === 'in_person')
                        <flux:field>
                            <flux:label>Audition Slot (minutes)</flux:label>
                            <flux:input wire:model="audition_timeslot" type="number" min="5" max="120" />
                            <flux:description>Duration per candidate for in-person scheduling.</flux:description>
                            <flux:error name="audition_timeslot" />
                        </flux:field>
                    @endif

                    <flux:field>
                        <flux:label>Upload Type</flux:label>
                        <flux:select wire:model.live="upload_type">
                            @foreach ($uploadTypes as $ut)
                                <flux:select.option value="{{ $ut->value }}">{{ $ut->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="upload_type" />
                    </flux:field>

                    @if (in_array($upload_type, ['audio', 'video'], true))
                        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 space-y-4">
                            <div>
                                <flux:heading size="sm">Expected Upload Files</flux:heading>
                                <flux:description>Generic file labels a Candidate is expected to upload (e.g. scales, solo, quintet). Order controls display order.</flux:description>
                            </div>

                            @if (count($upload_files) > 0)
                                <div class="space-y-2">
                                    @foreach ($upload_files as $id => $file)
                                        <div class="flex items-center gap-3" wire:key="upload-file-{{ $id }}">
                                            <span
                                                class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-semibold text-white shrink-0"
                                                style="background-color: {{ $this->uploadFileOrderColor((int) $file['order_by']) }}"
                                            >{{ $file['order_by'] }}</span>
                                            <div class="w-16 shrink-0">
                                                <flux:input wire:model="upload_files.{{ $id }}.order_by" type="number" min="1" max="99" />
                                            </div>
                                            <div class="flex-1">
                                                <flux:input wire:model="upload_files.{{ $id }}.name" />
                                            </div>
                                            <flux:button
                                                size="sm" variant="ghost" icon="x-mark"
                                                wire:click="removeUploadFile({{ $id }})"
                                                wire:confirm="Remove &quot;{{ $file['name'] }}&quot; from expected uploads?"
                                            >
                                                Remove
                                            </flux:button>
                                        </div>
                                    @endforeach
                                </div>

                                <flux:error name="upload_files" />
                                <flux:button size="sm" wire:click="saveUploadFiles">Save Upload Files</flux:button>
                            @else
                                <flux:text size="sm" class="text-zinc-400">No upload files defined yet.</flux:text>
                            @endif

                            <div class="flex items-end gap-3 pt-2 border-t border-zinc-200 dark:border-zinc-700">
                                <flux:field class="flex-1">
                                    <flux:label>New File Label</flux:label>
                                    <flux:input wire:model="new_upload_file_name" placeholder="e.g. scales" />
                                    <flux:error name="new_upload_file_name" />
                                </flux:field>
                                <flux:button wire:click="addUploadFile">Add</flux:button>
                            </div>
                        </div>
                    @endif

                    <flux:field>
                        <flux:label>Judge Count</flux:label>
                        <flux:input wire:model="judge_count" type="number" min="1" max="20" />
                        <flux:description>Number of actual judges in each audition room.</flux:description>
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
                        <flux:input wire:model="max_upper_voice_registrants" type="number" min="0" placeholder="No limit" />
                        <flux:error name="max_upper_voice_registrants" />
                    </flux:field>
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
                        <div class="grid grid-cols-1 gap-4">
                            <flux:field>
                                <flux:label>Start</flux:label>
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

                <div>
                    <flux:heading size="base" class="mb-3">Optional Fields Collected at Registration</flux:heading>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <flux:card size="sm">
                            <flux:heading size="sm" class="mb-3">Candidate</flux:heading>
                            <div class="space-y-3">
                                <flux:checkbox wire:model="birthday" label="Birthday" />
                                <flux:checkbox wire:model="shirt_size" label="Shirt Size" />
                                <flux:checkbox wire:model="height" label="Height" />
                                <flux:checkbox wire:model="home_address" label="Home Address" />
                            </div>
                        </flux:card>

                        <flux:card size="sm">
                            <flux:heading size="sm" class="mb-3">Emergency Contact</flux:heading>
                            <div class="space-y-3">
                                <flux:checkbox wire:model="emergency_contact_name" label="Emergency Contact Name" />
                                <flux:checkbox wire:model="emergency_contact_cell" label="Emergency Contact Cell" />
                                <flux:checkbox wire:model="emergency_contact_email" label="Emergency Contact Email" />
                            </div>
                        </flux:card>

                        <flux:card size="sm">
                            <flux:heading size="sm" class="mb-3">Other</flux:heading>
                            <div class="space-y-3">
                                <flux:checkbox wire:model="teacher_cell" label="Teacher Cell" />
                                <flux:checkbox wire:model="release_confidential_results" label="Release Confidential Results" />
                            </div>
                        </flux:card>
                    </div>
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
                                <div class="flex items-center gap-3" wire:key="ensemble-order-{{ $ensemble->id }}">
                                    <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $ensemble->name }}</span>
                                    <div class="w-20 shrink-0">
                                        <flux:input
                                            wire:model="ensemble_order.{{ $ensemble->id }}"
                                            type="number" min="1" max="99"
                                        />
                                    </div>
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

        {{-- Obligations --}}
        <flux:tab.panel name="obligations">
            <div class="mt-6 space-y-4 max-w-3xl">
                <div class="flex flex-wrap items-center gap-2">
                    @if ($obligation_status === 'published')
                        <flux:badge color="green">Published</flux:badge>
                        @if ($obligation_published_at)
                            <flux:text size="sm" class="text-zinc-500">since {{ $obligation_published_at }}</flux:text>
                        @endif
                    @else
                        <flux:badge color="zinc">Draft</flux:badge>
                    @endif

                    @if ($obligation_response_count > 0)
                        <flux:badge color="amber" size="sm">{{ $obligation_response_count }} teacher response{{ $obligation_response_count === 1 ? '' : 's' }}</flux:badge>
                    @endif
                </div>

                <flux:callout variant="info" icon="information-circle">
                    <flux:callout.text>
                        Available merge fields: @verbatim<code class="font-mono text-xs">{{versionShortName}}</code> and <code class="font-mono text-xs">{{versionName}}</code>@endverbatim — replaced with this Version's values when a teacher views or accepts.
                    </flux:callout.text>
                </flux:callout>

                <flux:field>
                    <flux:label>Title (optional)</flux:label>
                    <flux:input wire:model="obligation_title" placeholder="Teacher Obligations" />
                    <flux:error name="obligation_title" />
                </flux:field>

                <flux:field>
                    <flux:label>Obligations Text</flux:label>
                    <flux:editor wire:model="obligation_body" toolbar="heading bold italic underline | bullet ordered blockquote | link" />
                    <flux:error name="obligation_body" />
                </flux:field>

                @if ($errors->any())
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.text>Please correct the errors above.</flux:callout.text>
                    </flux:callout>
                @endif

                <div class="flex flex-wrap gap-3">
                    <flux:button variant="filled" wire:click="saveObligation">
                        Save
                    </flux:button>

                    @if ($obligation_status === 'published')
                        <flux:button
                            variant="filled"
                            wire:click="unpublishObligation"
                            wire:confirm="Unpublish these obligations? Teachers will no longer be able to view or respond until you republish."
                        >
                            Unpublish
                        </flux:button>
                    @else
                        <flux:button variant="primary" wire:click="publishObligation">
                            Publish
                        </flux:button>
                    @endif

                    <flux:modal.trigger name="obligation-preview">
                        <flux:button variant="ghost" icon="eye" wire:click="$refresh">
                            Preview
                        </flux:button>
                    </flux:modal.trigger>
                </div>
            </div>
        </flux:tab.panel>

        <flux:modal name="obligation-preview" class="md:w-[42rem]">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ $obligation_title !== '' ? $obligation_title : 'Teacher Obligations' }}</flux:heading>
                    <flux:subheading>Preview — {{ $version->name }}</flux:subheading>
                </div>

                <flux:callout variant="secondary" icon="information-circle">
                    <flux:callout.text>
                        This reflects your current unsaved edits with merge fields resolved, exactly as an invited teacher will see it. It does not save or publish anything.
                    </flux:callout.text>
                </flux:callout>

                @if (trim(strip_tags($obligationPreviewBody)) === '')
                    <flux:text class="text-zinc-500">Nothing to preview yet — add some obligations text first.</flux:text>
                @else
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4">
                        <div class="obligation-content text-zinc-700 dark:text-zinc-300">
                            {!! $obligationPreviewBody !!}
                        </div>
                    </div>
                @endif

                <div class="flex gap-2">
                    <flux:spacer />
                    <flux:modal.close>
                        <flux:button variant="ghost">Close</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        </flux:modal>

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
                            <flux:input wire:model.live.debounce.300ms="assign_email" placeholder="person@example.com" />
                            <flux:error name="assign_email" />
                        </flux:field>

                        @if ($assignEmailSuggestions->isNotEmpty())
                            <div class="flex flex-col gap-2">
                                @foreach ($assignEmailSuggestions as $user)
                                    <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                        <div>
                                            <flux:text class="font-medium">{{ $user->name }}</flux:text>
                                            <flux:text size="sm" class="text-zinc-500">{{ $user->email }}</flux:text>
                                        </div>
                                        <flux:button size="sm" wire:click="selectAssignEmail({{ $user->id }})">Select</flux:button>
                                    </div>
                                @endforeach
                            </div>
                        @endif

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
    </flux:tab.group>
</div>
