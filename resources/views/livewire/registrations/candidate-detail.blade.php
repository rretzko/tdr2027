<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('registrations.index') }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Registrations</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('registrations.version', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>{{ $candidate->program_name ?: $candidate->ref }}</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">{{ $candidate->program_name ?: '(No program name)' }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 font-mono">{{ $candidate->ref }}</flux:text>
        </div>

        <div class="flex items-center gap-2">
            @php $rawStatus = $candidate->getRawOriginal('status'); @endphp
            @if ($rawStatus === 'eligible')
                <flux:badge color="zinc">Eligible</flux:badge>
            @elseif ($rawStatus === 'pending')
                <flux:badge color="amber">Pending</flux:badge>
            @elseif ($rawStatus === 'registered')
                <flux:badge color="green">Registered</flux:badge>
            @elseif ($rawStatus === 'teacher_withdrawn')
                <flux:badge color="red">Withdrawn</flux:badge>
            @else
                <flux:badge color="zinc" class="capitalize">{{ str_replace('_', ' ', $rawStatus) }}</flux:badge>
            @endif

            @if (in_array($rawStatus, ['eligible', 'pending', 'registered']))
                <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refreshStatus">
                    Refresh Status
                </flux:button>
            @endif
        </div>
    </div>

    {{-- Checklist summary --}}
    <flux:card class="mb-6">
        <flux:heading size="sm" class="mb-3">Registration Checklist</flux:heading>
        <div class="flex flex-wrap gap-2">
            @foreach ($checklistDefs as $def)
                @php
                    $done = ($def['check'])($candidate);
                    $partial = ! $done && isset($def['partial']) && ($def['partial'])($candidate);
                @endphp
                <span class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-medium
                    {{ $done ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400' : ($partial ? 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' : 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400') }}">
                    @if ($done)
                        <flux:icon.check-circle variant="micro" />
                    @elseif ($partial)
                        <flux:icon.minus-circle variant="micro" />
                    @else
                        <flux:icon.x-circle variant="micro" />
                    @endif
                    {{ $def['label'] }}
                </span>
            @endforeach
        </div>
    </flux:card>

    <div class="space-y-6">

        {{-- Student --}}
        <flux:card>
            <div class="flex items-center justify-between mb-3">
                <flux:heading size="sm">Student</flux:heading>
                <flux:button size="sm" variant="ghost" icon="pencil" wire:click="editStudent">Edit</flux:button>
            </div>

            <div class="space-y-2 text-sm">
                <div class="flex gap-2">
                    <span class="text-zinc-500 w-28 shrink-0">Name</span>
                    <span>{{ $candidate->student->user->first_name }} {{ $candidate->student->user->last_name }}</span>
                </div>

                <div class="flex gap-2">
                    <span class="text-zinc-500 w-28 shrink-0">Voice Part</span>
                    <span>{{ $candidate->voicePart?->name ?? '—' }}</span>
                </div>

                @if ((bool) $version->birthday || $candidate->student->birthday !== null)
                    <div class="flex gap-2">
                        <span class="text-zinc-500 w-28 shrink-0">Birthday</span>
                        @php $bday = $candidate->student->getRawOriginal('birthday'); @endphp
                        <span>{{ $bday ? \Carbon\Carbon::parse((string) $bday)->format('M j, Y') : '—' }}</span>
                    </div>
                @endif

                @if ((bool) $version->height || $candidate->student->height !== null)
                    <div class="flex gap-2">
                        <span class="text-zinc-500 w-28 shrink-0">Height</span>
                        <span>{{ $candidate->student->height !== null ? $candidate->student->height.'"' : '—' }}</span>
                    </div>
                @endif
            </div>
        </flux:card>

        {{-- Home Address (only when this Version requires it) --}}
        @if ((bool) $version->home_address)
            <flux:card>
                <div class="flex items-center justify-between mb-3">
                    <flux:heading size="sm">Home Address</flux:heading>
                    <flux:button size="sm" variant="ghost" icon="pencil" wire:click="editHomeAddress">
                        {{ $candidate->student->homeAddress !== null ? 'Edit' : 'Add' }}
                    </flux:button>
                </div>

                @if ($candidate->student->homeAddress !== null)
                    <flux:text size="sm">{{ $candidate->student->homeAddress->formatted }}</flux:text>
                @else
                    <flux:text size="sm" class="text-zinc-500">Not yet provided.</flux:text>
                @endif
            </flux:card>
        @endif

        {{-- Emergency Contacts --}}
        <flux:card>
            <div class="flex items-center justify-between mb-3">
                <flux:heading size="sm">Emergency Contacts</flux:heading>
                @if ((bool) $version->emergency_contact_name)
                    <flux:button size="sm" variant="ghost" icon="plus" wire:click="addEmergencyContact">Add</flux:button>
                @endif
            </div>

            @if ($candidate->student->emergencyContacts->isNotEmpty())
                <div class="space-y-3">
                    @foreach ($candidate->student->emergencyContacts as $ec)
                        <div class="flex items-start justify-between gap-3 text-sm border-b border-zinc-100 dark:border-zinc-800 pb-3 last:border-0 last:pb-0">
                            <div>
                                <div class="font-medium">{{ $ec->name }}</div>
                                <div class="text-zinc-500">{{ $ec->getRawOriginal('relationship') }}</div>
                                @if ($ec->cell_phone)
                                    <div class="text-zinc-500">Cell: {{ $ec->cell_phone }}</div>
                                @endif
                                @if ($ec->home_phone)
                                    <div class="text-zinc-500">Home: {{ $ec->home_phone }}</div>
                                @endif
                                @if ($ec->email)
                                    <div class="text-zinc-500">{{ $ec->email }}</div>
                                @endif
                            </div>
                            <flux:button size="sm" variant="ghost" icon="pencil" wire:click="editEmergencyContact({{ $ec->id }})">Edit</flux:button>
                        </div>
                    @endforeach
                </div>
            @else
                <flux:text size="sm" class="text-zinc-500">No emergency contacts yet.</flux:text>
            @endif
        </flux:card>

        {{-- Program Name --}}
        <flux:card>
            <div class="flex items-center justify-between mb-1">
                <flux:heading size="sm">Program Name</flux:heading>
                <flux:button size="sm" variant="ghost" icon="pencil" wire:click="editProgramName">Edit</flux:button>
            </div>
            <flux:text size="sm" class="text-zinc-500 mb-2">
                How this student's name appears in the program. Required for registration.
            </flux:text>
            <flux:text size="sm">{{ $candidate->program_name ?: 'Not yet set.' }}</flux:text>
        </flux:card>

        {{-- Application --}}
        @if ($application !== null)
            <flux:card>
                <div class="flex items-center justify-between mb-3">
                    <flux:heading size="sm">Application</flux:heading>
                    <flux:button size="sm" variant="ghost" icon="eye" wire:click="viewApplication">View</flux:button>
                </div>

                @if ($version->application_type === \App\Enums\ApplicationType::Pdf)
                    <flux:badge color="{{ $candidate->application_certified_at !== null ? 'green' : 'amber' }}" size="sm">
                        {{ $candidate->application_certified_at !== null ? 'Certified' : 'Not Certified' }}
                    </flux:badge>
                @else
                    <div class="flex flex-wrap gap-2">
                        <flux:badge color="{{ $candidate->application_candidate_signed_at !== null ? 'green' : 'amber' }}" size="sm">
                            {{ $candidate->application_candidate_signed_at !== null ? 'Candidate Signed' : 'Candidate Not Signed' }}
                        </flux:badge>
                        <flux:badge color="{{ $candidate->application_parent_signed_at !== null ? 'green' : 'amber' }}" size="sm">
                            {{ $candidate->application_parent_signed_at !== null ? 'Parent Signed' : 'Parent Not Signed' }}
                        </flux:badge>
                    </div>
                @endif
            </flux:card>
        @endif

        {{-- Recordings (remote audition only) --}}
        @if ($uploadSlots->isNotEmpty())
            <flux:card>
                <flux:heading size="sm" class="mb-3">Recordings</flux:heading>

                <div class="space-y-4">
                    @foreach ($uploadSlots as $slot)
                        @php $upload = $candidateUploads->get($slot->id); @endphp
                        <div class="border-b border-zinc-100 dark:border-zinc-800 pb-4 last:border-0 last:pb-0">
                            <div class="flex items-center justify-between gap-3 mb-2">
                                <div class="font-medium text-sm">{{ $slot->name }}</div>
                                <div class="flex items-center gap-2">
                                    @if ($upload !== null && $upload->flagged_at !== null)
                                        <flux:badge color="red" size="sm" icon="flag">Review Suggested</flux:badge>
                                    @endif
                                    @if ($upload === null)
                                        <flux:badge color="zinc" size="sm">Not Uploaded</flux:badge>
                                    @elseif ($upload->getRawOriginal('status') === 'approved')
                                        <flux:badge color="green" size="sm">Approved</flux:badge>
                                    @else
                                        <flux:badge color="amber" size="sm">Pending Review</flux:badge>
                                    @endif
                                </div>
                            </div>

                            @if ($upload !== null && $upload->flagged_at !== null)
                                <flux:callout variant="warning" icon="flag" class="mb-2">
                                    <flux:callout.text>{{ $upload->flag_reason }}</flux:callout.text>
                                </flux:callout>
                            @endif

                            @if ($upload !== null)
                                @php
                                    // New objects in this bucket default to private (confirmed
                                    // via a direct 403 on a plain ->url() while diagnosing
                                    // "0:00 / 0:00, won't play") — a signed URL is required.
                                    $recordingUrl = \Illuminate\Support\Facades\Storage::disk('s3')->temporaryUrl($upload->url, now()->addMinutes(30));
                                @endphp
                                @if ($upload->original_filename)
                                    <flux:text size="sm" class="text-zinc-500 mb-1">Uploaded as: {{ $upload->original_filename }}</flux:text>
                                @endif
                                <div class="mb-2">
                                    @if ($version->getRawOriginal('upload_type') === 'video')
                                        <video controls preload="metadata" class="w-full max-w-sm rounded">
                                            <source src="{{ $recordingUrl }}">
                                        </video>
                                    @else
                                        {{-- preload="metadata" (not "none") so the real duration
                                        shows on load instead of a stuck 0:00 / 0:00 until play
                                        is pressed — fetches just the file's header, not the
                                        whole recording. --}}
                                        <audio controls preload="metadata" class="w-full">
                                            <source src="{{ $recordingUrl }}">
                                        </audio>
                                    @endif
                                </div>
                            @endif

                            <div class="flex flex-wrap gap-2">
                                <flux:button size="sm" variant="ghost" icon="arrow-up-tray" wire:click="uploadRecording({{ $slot->id }})">
                                    {{ $upload !== null ? 'Replace' : 'Upload' }}
                                </flux:button>

                                @if ($upload !== null && $upload->getRawOriginal('status') === 'pending')
                                    <flux:button size="sm" variant="ghost" icon="check" wire:click="approveRecording({{ $upload->id }})">
                                        Approve
                                    </flux:button>
                                    <flux:button
                                        size="sm" variant="ghost" icon="x-mark"
                                        wire:click="rejectRecording({{ $upload->id }})"
                                        wire:confirm="Reject and delete this recording? The teacher can upload a replacement afterward."
                                    >
                                        Reject
                                    </flux:button>
                                @elseif ($upload !== null)
                                    <flux:button
                                        size="sm" variant="ghost" icon="x-mark"
                                        wire:click="rejectRecording({{ $upload->id }})"
                                        wire:confirm="Remove this approved recording? The teacher can upload a replacement afterward."
                                    >
                                        Remove
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @endif

        {{-- Payment --}}
        <flux:card>
            <flux:heading size="sm" class="mb-1">Payment</flux:heading>

            @if ($overpaymentCents > 0)
                <div class="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40 px-3 py-2 mb-2 text-sm text-amber-800 dark:text-amber-300">
                    <flux:icon.exclamation-triangle variant="micro" class="shrink-0" />
                    Overpaid by ${{ number_format($overpaymentCents / 100, 2) }}.
                </div>
            @endif

            <div class="flex items-center justify-between mb-2">
                <flux:heading size="xs" class="text-zinc-500">Payment History</flux:heading>
                <div class="flex items-center gap-2">
                    {{-- Registration is mutually exclusive with the other
                         two by timing (see FeeType), but participation and
                         housing can both be due at once once the Version
                         closes — independent @if blocks, not @elseif. --}}
                    @if ($registrationFeeDue)
                        <flux:button size="sm" variant="primary" icon="credit-card" wire:click="payNow('registration')">Pay Registration Fee</flux:button>
                    @endif
                    @if ($participationFeeDue)
                        <flux:button size="sm" variant="primary" icon="credit-card" wire:click="payNow('participation')">Pay Participation Fee</flux:button>
                    @endif
                    @if ($housingFeeDue)
                        <flux:button size="sm" variant="primary" icon="credit-card" wire:click="payNow('housing')">Pay Housing Fee</flux:button>
                    @endif
                    <flux:button size="sm" variant="ghost" icon="plus" wire:click="recordPayment">Record Payment</flux:button>
                </div>
            </div>

            @if ($candidatePayments->isNotEmpty())
                <div class="space-y-2">
                    @foreach ($candidatePayments as $payment)
                        @php $allocatedAmount = $payment->allocations->first()?->amountInDollars() ?? $payment->amountInDollars(); @endphp
                        <div class="flex items-center justify-between text-sm border-b border-zinc-100 dark:border-zinc-800 pb-2 last:border-0 last:pb-0">
                            <div>
                                <span class="font-medium">{{ $allocatedAmount < 0 ? '-' : '' }}${{ number_format(abs($allocatedAmount), 2) }}</span>
                                @if ($payment->paid_at)
                                    <span class="text-zinc-500"> — {{ $payment->paid_at->format('M j, Y') }}</span>
                                @endif
                                @if ($payment->reference_number)
                                    <div class="text-zinc-500">Ref: {{ $payment->reference_number }}</div>
                                @endif
                                @if ($payment->comments)
                                    <div class="text-zinc-500">{{ $payment->comments }}</div>
                                @endif
                            </div>
                            @php
                                $statusColor = match ($payment->status) {
                                    \App\Enums\PaymentTransactionStatus::Completed => 'green',
                                    \App\Enums\PaymentTransactionStatus::Pending => 'amber',
                                    \App\Enums\PaymentTransactionStatus::Failed => 'red',
                                    \App\Enums\PaymentTransactionStatus::Refunded => 'zinc',
                                };
                            @endphp
                            <flux:badge size="sm" :color="$statusColor">{{ $payment->status->label() }}</flux:badge>
                        </div>
                    @endforeach
                </div>
            @else
                <flux:text size="sm" class="text-zinc-500">No payments recorded yet.</flux:text>
            @endif
        </flux:card>

    </div>

    {{-- Edit Student modal --}}
    <flux:modal name="edit-student" class="w-full max-w-md">
        <div class="space-y-6">
            <flux:heading>Edit Student</flux:heading>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>First Name</flux:label>
                    <flux:input wire:model="edit_first_name" />
                    <flux:error name="edit_first_name" />
                </flux:field>

                <flux:field>
                    <flux:label>Last Name</flux:label>
                    <flux:input wire:model="edit_last_name" />
                    <flux:error name="edit_last_name" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>Voice Part</flux:label>
                <flux:select wire:model="edit_voice_part_id" placeholder="Select a voice part...">
                    @foreach ($voiceParts as $voicePart)
                        <flux:select.option value="{{ $voicePart->id }}">{{ $voicePart->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="edit_voice_part_id" />
            </flux:field>

            <flux:field>
                <flux:label>Birthday</flux:label>
                <flux:input wire:model="edit_birthday" type="date" />
                <flux:error name="edit_birthday" />
            </flux:field>

            <flux:field>
                <flux:label>Height (in)</flux:label>
                <flux:select wire:model="edit_height" placeholder="Select height...">
                    @for ($inches = 30; $inches <= 84; $inches++)
                        <flux:select.option value="{{ $inches }}">{{ $inches }}" ({{ intdiv($inches, 12) }}' {{ $inches % 12 }}")</flux:select.option>
                    @endfor
                </flux:select>
                <flux:error name="edit_height" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="saveStudent">Save Changes</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Edit Home Address modal --}}
    @if ((bool) $version->home_address)
        <flux:modal name="edit-home-address" class="w-full max-w-md">
            <div class="space-y-6">
                <flux:heading>{{ $candidate->student->homeAddress !== null ? 'Edit Home Address' : 'Add Home Address' }}</flux:heading>

                <flux:input wire:model="edit_home_address1" label="Address line 1" />
                <flux:error name="edit_home_address1" />
                <flux:input wire:model="edit_home_address2" label="Address line 2 (optional)" />
                <flux:error name="edit_home_address2" />

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <flux:input wire:model="edit_home_city" label="City" class="sm:col-span-1" />
                    <flux:input wire:model="edit_home_geo_state" label="State" maxlength="2" />
                    <flux:input wire:model="edit_home_zip_code" label="Zip code" />
                </div>
                <flux:error name="edit_home_city" />
                <flux:error name="edit_home_geo_state" />
                <flux:error name="edit_home_zip_code" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" wire:click="saveHomeAddress">Save Changes</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

    {{-- Add/Edit Emergency Contact modal --}}
    @if ((bool) $version->emergency_contact_name || $candidate->student->emergencyContacts->isNotEmpty())
        <flux:modal name="add-emergency-contact" class="w-full max-w-md">
        <div class="space-y-4">
            <flux:heading>{{ $editingEmergencyContactId !== null ? 'Edit Emergency Contact' : 'Add Emergency Contact' }}</flux:heading>

            <flux:field>
                <flux:label>Name</flux:label>
                <flux:input wire:model="ec_name" placeholder="Full name" />
                <flux:error name="ec_name" />
            </flux:field>

            <flux:field>
                <flux:label>Relationship</flux:label>
                <flux:select wire:model="ec_relationship">
                    <flux:select.option value="">— select —</flux:select.option>
                    @foreach ($relationships as $rel)
                        <flux:select.option value="{{ $rel->value }}">{{ $rel->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="ec_relationship" />
            </flux:field>

            <flux:field>
                <flux:label>Cell Phone{{ (bool) $version->emergency_contact_cell ? '' : ' (optional)' }}</flux:label>
                <flux:input wire:model="ec_cell_phone" placeholder="(555) 000-0000" />
                <flux:error name="ec_cell_phone" />
            </flux:field>

            <flux:field>
                <flux:label>Home Phone (optional)</flux:label>
                <flux:input wire:model="ec_home_phone" placeholder="(555) 000-0000" />
                <flux:error name="ec_home_phone" />
            </flux:field>

            <flux:field>
                <flux:label>Email{{ (bool) $version->emergency_contact_email ? '' : ' (optional)' }}</flux:label>
                <flux:input wire:model="ec_email" type="email" placeholder="email@example.com" />
                <flux:error name="ec_email" />
            </flux:field>

            @if ($errors->hasAny(['ec_name', 'ec_relationship', 'ec_cell_phone', 'ec_home_phone', 'ec_email']))
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>Please correct the errors above.</flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="saveEmergencyContact">{{ $editingEmergencyContactId !== null ? 'Save Changes' : 'Save Emergency Contact' }}</flux:button>
            </div>
        </div>
        </flux:modal>
    @endif

    {{-- Edit Program Name modal --}}
    <flux:modal name="edit-program-name" class="w-full max-w-md">
        <div class="space-y-6">
            <flux:heading>Edit Program Name</flux:heading>
            <flux:text size="sm" class="text-zinc-500">
                How this student's name appears in the program. Required for registration.
            </flux:text>

            <flux:field>
                <flux:label>Program Name</flux:label>
                <flux:input wire:model="program_name" placeholder="e.g. Jane Smith" />
                <flux:error name="program_name" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="saveProgramName">Save</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- View Application modal --}}
    @if ($applicationDoc !== null)
        <flux:modal name="view-application" class="w-full max-w-3xl" scroll="body">
            <div class="space-y-6">
                <flux:heading>Application</flux:heading>

                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 max-h-[60vh] overflow-y-auto">
                    @include('candidate-application.document', [
                        'version' => $version,
                        'data' => $applicationDoc['data'],
                        'studentBody' => $applicationDoc['studentBody'],
                        'parentBody' => $applicationDoc['parentBody'],
                        'teacherBody' => $applicationDoc['teacherBody'],
                        'scheduleBody' => $applicationDoc['scheduleBody'],
                        'policiesBody' => $applicationDoc['policiesBody'],
                        'showTeacherSection' => $applicationDoc['showTeacherSection'],
                        'candidateSignedAt' => $applicationDoc['candidateSignedAt'],
                        'parentSignedAt' => $applicationDoc['parentSignedAt'],
                    ])
                </div>

                @if ($version->application_type === \App\Enums\ApplicationType::Pdf)
                    <flux:checkbox
                        :checked="$candidate->application_certified_at !== null"
                        wire:click="toggleApplicationCertified"
                        wire:confirm="{{ $candidate->application_certified_at !== null ? 'Undo this certification?' : 'Certify that these signatures are present, complete, and have integrity?' }}"
                        label="I certify that the student, parent/guardian, teacher, and principal signatures are present, complete, and have integrity on the physical copy."
                    />
                    @if ($candidate->application_certified_at !== null)
                        <flux:text size="sm" class="text-zinc-500">
                            Certified by {{ $candidate->applicationCertifiedBy?->name }}
                            on {{ $candidate->application_certified_at->format('M j, Y g:ia') }}.
                        </flux:text>
                    @endif
                @else
                    <div class="space-y-3">
                        <flux:checkbox
                            :checked="$candidate->application_candidate_signed_at !== null"
                            wire:click="toggleApplicationCandidateSigned"
                            label="Candidate has signed"
                        />
                        <flux:checkbox
                            :checked="$candidate->application_parent_signed_at !== null"
                            wire:click="toggleApplicationParentSigned"
                            label="Parent/Guardian has signed"
                        />
                    </div>
                @endif

                <div class="flex justify-between items-center">
                    <flux:button
                        size="sm" variant="ghost" icon="arrow-down-tray"
                        :href="route('registrations.candidate.application-pdf', [$version, $candidate])"
                    >
                        Download PDF{{ $version->application_type === \App\Enums\ApplicationType::EApplication ? ' (optional copy)' : '' }}
                    </flux:button>
                    <flux:modal.close>
                        <flux:button variant="ghost">Close</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        </flux:modal>
    @endif

    {{-- Upload Recording modal --}}
    @if ($uploadSlots->isNotEmpty())
        <flux:modal name="upload-recording" class="w-full max-w-md">
            <div class="space-y-6">
                <flux:heading>Upload Recording</flux:heading>

                <flux:field>
                    <flux:label>File</flux:label>
                    <input
                        type="file"
                        wire:model="newRecordingFile"
                        accept="{{ $version->getRawOriginal('upload_type') === 'video' ? 'video/*' : 'audio/*' }}"
                        class="block w-full text-sm text-zinc-600 file:mr-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-300"
                    />
                    <div wire:loading wire:target="newRecordingFile">
                        <flux:text size="sm" class="mt-1 text-zinc-400">Uploading…</flux:text>
                    </div>
                    <flux:error name="newRecordingFile" />
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" wire:click="saveRecording">Save</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

    {{-- Record Payment modal — manual entry (check/PO/cash/other) only; a real electronic payment goes through Pay Now --}}
    <flux:modal name="record-payment" class="w-full max-w-md">
        <div class="space-y-6">
            <flux:heading>Record Payment</flux:heading>
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.text>
                    This records a manual entry only — it does not process a live payment.
                </flux:callout.text>
            </flux:callout>

            <flux:field>
                <flux:label>Payment Type</flux:label>
                <flux:select wire:model="payment_type" placeholder="Select a payment type...">
                    <flux:select.option value="check">Check</flux:select.option>
                    <flux:select.option value="purchase_order">Purchase Order</flux:select.option>
                    <flux:select.option value="cash">Cash</flux:select.option>
                    <flux:select.option value="other">Other</flux:select.option>
                    <flux:select.option value="refund">Refund</flux:select.option>
                </flux:select>
                <flux:error name="payment_type" />
            </flux:field>

            <flux:field>
                <flux:label>Amount ($)</flux:label>
                <flux:description>For a refund, enter the amount returned — it's recorded as a negative amount.</flux:description>
                <flux:input wire:model="payment_amount" type="number" step="0.01" min="0.01" placeholder="0.00" />
                <flux:error name="payment_amount" />
            </flux:field>

            <flux:field>
                <flux:label>Date Paid</flux:label>
                <flux:input wire:model="payment_paid_at" type="date" />
                <flux:error name="payment_paid_at" />
            </flux:field>

            <flux:field>
                <flux:label>Reference Number (optional)</flux:label>
                <flux:input wire:model="payment_reference_number" placeholder="e.g. confirmation #" />
                <flux:error name="payment_reference_number" />
            </flux:field>

            <flux:field>
                <flux:label>Comments (optional)</flux:label>
                <flux:textarea wire:model="payment_comments" rows="3" />
                <flux:error name="payment_comments" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="savePayment">Save</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
