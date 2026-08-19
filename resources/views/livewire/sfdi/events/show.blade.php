<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('sfdi.events.index') }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">My Events</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>{{ $candidate->version->name }}</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">{{ $candidate->version->name }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $candidate->version->event->name }}</flux:text>
        </div>

        @php
            $rawStatus = $candidate->getRawOriginal('status');
            $statusColor = match ($rawStatus) {
                'eligible' => 'zinc',
                'pending' => 'amber',
                'registered' => 'green',
                'withdrew', 'teacher_withdrawn' => 'red',
                default => 'zinc',
            };
        @endphp
        <div class="flex items-center gap-2">
            <flux:button id="tour-start" data-auto-start="{{ auth()->user()->dismissed_sfdi_candidate_orientation_at === null ? '1' : '0' }}" size="sm" variant="ghost" icon="sparkles" type="button">Take a tour</flux:button>
            <flux:button id="tour-pitch-files" size="sm" variant="ghost" icon="musical-note" wire:click="viewPitchFiles">Pitch Files</flux:button>
            <flux:badge id="tour-status-badge" :color="$statusColor">{{ $candidate->status->label() }}</flux:badge>
        </div>
    </div>

    @if ($writeActionsBlocked)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6">
            <flux:callout.text>
                @if ($obligationsBlocked)
                    Registration is temporarily paused — your teacher needs to review this Event's requirements before you can continue.
                @else
                    This Event is no longer open for changes at your current status ({{ $candidate->status->label() }}).
                @endif
            </flux:callout.text>
        </flux:callout>
    @endif

    <flux:card id="tour-candidate-requirements" class="mb-6">
        <flux:heading size="sm" class="mb-1">Candidate Requirements</flux:heading>
        <flux:text size="sm" class="text-zinc-500 mb-3">
            What's needed before your teacher can register you for this Event. Anything not yet done links to the page where you can complete it.
        </flux:text>

        @if ($checklistDefs === [])
            <flux:text size="sm" class="text-zinc-500">This Event has no candidate requirements to complete.</flux:text>
        @else
            <div class="flex flex-wrap gap-2">
                @foreach ($checklistDefs as $def)
                    @php
                        $done = ($def['check'])($candidate);
                        $partial = ! $done && isset($def['partial']) && ($def['partial'])($candidate);
                        $href = match ($def['label']) {
                            'Emergency contact' => route('sfdi.emergency-contacts'),
                            default => route('sfdi.student-details'),
                        };
                    @endphp
                    <a href="{{ $href }}" wire:navigate class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-medium transition hover:brightness-95
                        {{ $done ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400' : ($partial ? 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' : 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400') }}">
                        @if ($done)
                            <flux:icon.check-circle variant="micro" />
                        @elseif ($partial)
                            <flux:icon.minus-circle variant="micro" />
                        @else
                            <flux:icon.x-circle variant="micro" />
                        @endif
                        {{ $def['label'] }}
                    </a>
                @endforeach
            </div>
        @endif
    </flux:card>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Registration --}}
        <flux:card id="tour-registration-card">
            <div class="flex items-center justify-between mb-3">
                <flux:heading size="sm">Registration</flux:heading>
            </div>

            <div class="space-y-3 text-sm">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <flux:text class="text-zinc-500">Voice Part</flux:text>
                        <flux:text class="font-medium">{{ $candidate->voicePart->name ?? '—' }}</flux:text>
                    </div>
                    @unless ($writeActionsBlocked)
                        <flux:button size="sm" variant="ghost" icon="pencil" wire:click="editVoicePart">Edit</flux:button>
                    @endunless
                </div>

                <div class="flex items-center justify-between gap-2">
                    <div>
                        <flux:text class="text-zinc-500">Program Name</flux:text>
                        <flux:text class="font-medium">{{ $candidate->program_name ?: '—' }}</flux:text>
                    </div>
                    @unless ($writeActionsBlocked)
                        <flux:button size="sm" variant="ghost" icon="pencil" wire:click="editProgramName">Edit</flux:button>
                    @endunless
                </div>

                <div class="flex items-center justify-between gap-2">
                    <flux:text class="text-zinc-500">Teacher</flux:text>
                    <flux:text class="font-medium">{{ $candidate->teacher->user->name }}</flux:text>
                </div>
            </div>

            @unless ($writeActionsBlocked)
                <div class="mt-4 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                    <flux:button
                        size="sm" variant="ghost" icon="x-circle"
                        wire:click="withdraw"
                        wire:confirm="Withdraw from this Event? Your teacher will be able to see that you've withdrawn."
                    >
                        Withdraw from this Event
                    </flux:button>
                </div>
            @endunless
        </flux:card>

        {{-- Application --}}
        @if ($application !== null)
            <flux:card id="tour-application-card">
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
            <flux:card id="tour-recordings-card">
                <flux:heading size="sm" class="mb-3">Recordings</flux:heading>

                <div class="space-y-4">
                    @foreach ($uploadSlots as $slot)
                        @php
                            $upload = $candidateUploads->get($slot->id);
                            $approved = $upload !== null && $upload->getRawOriginal('status') === 'approved';
                            $canWriteThisSlot = ! $writeActionsBlocked && ! $approved;
                        @endphp
                        <div class="border-b border-zinc-100 dark:border-zinc-800 pb-4 last:border-0 last:pb-0">
                            <div class="flex items-center justify-between gap-3 mb-2">
                                <div class="font-medium text-sm">{{ $slot->name }}</div>
                                <div class="flex items-center gap-2">
                                    @if ($upload !== null && $upload->flagged_at !== null)
                                        <flux:badge color="red" size="sm" icon="flag">Review Suggested</flux:badge>
                                    @endif
                                    @if ($upload === null)
                                        <flux:badge color="zinc" size="sm">Not Uploaded</flux:badge>
                                    @elseif ($approved)
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
                                        <audio controls preload="metadata" class="w-full">
                                            <source src="{{ $recordingUrl }}">
                                        </audio>
                                    @endif
                                </div>
                            @endif

                            @if ($canWriteThisSlot)
                                <div class="flex flex-wrap gap-2">
                                    <flux:button size="sm" variant="ghost" icon="arrow-up-tray" wire:click="uploadRecording({{ $slot->id }})">
                                        {{ $upload !== null ? 'Replace' : 'Upload' }}
                                    </flux:button>

                                    @if ($upload !== null)
                                        <flux:button
                                            size="sm" variant="ghost" icon="x-mark"
                                            wire:click="deleteRecording({{ $upload->id }})"
                                            wire:confirm="Remove this recording? You can upload a replacement afterward."
                                        >
                                            Delete
                                        </flux:button>
                                    @endif
                                </div>
                            @elseif ($approved)
                                <flux:text size="sm" class="text-zinc-500">
                                    Your teacher has approved this recording — contact them if it needs to change.
                                </flux:text>
                            @endif
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @endif

        {{-- Payment --}}
        <flux:card id="tour-payment-card">
            <flux:heading size="sm" class="mb-1">Payment</flux:heading>

            @if ($overpaymentCents > 0)
                <div class="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40 px-3 py-2 mb-2 text-sm text-amber-800 dark:text-amber-300">
                    <flux:icon.exclamation-triangle variant="micro" class="shrink-0" />
                    Overpaid by ${{ number_format($overpaymentCents / 100, 2) }}.
                </div>
            @endif

            <div class="flex items-center justify-between mb-2">
                <flux:heading size="xs" class="text-zinc-500">Payment History</flux:heading>
                @if ($registrationFeeDue || $participationFeeDue || $housingFeeDue)
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
                    </div>
                @endif
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

    {{-- Pitch Files modal --}}
    <flux:modal name="pitch-files" class="w-full max-w-3xl" scroll="body">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $candidate->voicePart ? "{$candidate->voicePart->name} Pitch Files" : 'Pitch Files' }}</flux:heading>
                <flux:text size="sm" class="text-zinc-500">{{ $candidate->version->name }} — reference audio for your voice part</flux:text>
            </div>

            @if ($pitchFiles->isEmpty())
                <flux:callout variant="info" icon="magnifying-glass">
                    <flux:callout.text>
                        @if (! $pitchFilesVisible)
                            Pitch files for this Event aren't shared with candidates — ask your teacher.
                        @else
                            No pitch files have been added for your voice part yet.
                        @endif
                    </flux:callout.text>
                </flux:callout>
            @else
                {{-- Cards below lg:, table at lg:+ --}}
                <div class="lg:hidden space-y-3">
                    @foreach ($pitchFiles as $pitchFile)
                        @php
                            $pitchFileUrl = \Illuminate\Support\Facades\Storage::disk('s3')->temporaryUrl($pitchFile->url, now()->addMinutes(30));
                        @endphp
                        <flux:card size="sm">
                            <div class="flex items-start justify-between gap-3 mb-2">
                                <div class="min-w-0">
                                    <flux:heading size="base" class="truncate">{{ $pitchFile->name }}</flux:heading>
                                    <flux:badge color="zinc" size="sm">{{ $pitchFile->voicePart->name }}</flux:badge>
                                </div>
                                @if (strtolower($pitchFile->name) === 'pdf')
                                    <a href="{{ $pitchFileUrl }}" target="_blank" rel="noopener" class="shrink-0 text-sm text-blue-600 hover:underline dark:text-blue-400">
                                        Read
                                    </a>
                                @else
                                    <button
                                        type="button"
                                        @click="
                                            let panel = $el.nextElementSibling;
                                            let isOpen = panel.style.gridTemplateRows === '1fr';
                                            panel.style.gridTemplateRows = isOpen ? '0fr' : '1fr';
                                            $el.textContent = isOpen ? 'Listen' : 'Hide';
                                        "
                                        class="shrink-0 text-sm text-blue-600 hover:underline dark:text-blue-400"
                                    >Listen</button>
                                @endif
                            </div>

                            @if (strtolower($pitchFile->name) !== 'pdf')
                                <div class="grid transition-[grid-template-rows] duration-200 ease-out mb-2" style="grid-template-rows: 0fr;">
                                    <div class="overflow-hidden min-h-0">
                                        <audio controls preload="metadata" class="w-full">
                                            <source src="{{ $pitchFileUrl }}">
                                        </audio>
                                    </div>
                                </div>
                            @endif

                            @if ($pitchFile->description)
                                <flux:text size="sm" class="text-zinc-500">{{ $pitchFile->description }}</flux:text>
                            @endif
                        </flux:card>
                    @endforeach
                </div>

                <div class="hidden lg:block">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Name</flux:table.column>
                            <flux:table.column>Voice Part</flux:table.column>
                            <flux:table.column>Description</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($pitchFiles as $pitchFile)
                                @php
                                    $pitchFileUrl = \Illuminate\Support\Facades\Storage::disk('s3')->temporaryUrl($pitchFile->url, now()->addMinutes(30));
                                @endphp
                                <flux:table.row :key="$pitchFile->id">
                                    <flux:table.cell>
                                        <div class="font-medium">{{ $pitchFile->name }}</div>
                                        @if (strtolower($pitchFile->name) === 'pdf')
                                            <a href="{{ $pitchFileUrl }}" target="_blank" rel="noopener" class="text-sm text-blue-600 hover:underline dark:text-blue-400">
                                                Read
                                            </a>
                                        @else
                                            <button
                                                type="button"
                                                @click="
                                                    let panel = $el.nextElementSibling;
                                                    let isOpen = panel.style.gridTemplateRows === '1fr';
                                                    panel.style.gridTemplateRows = isOpen ? '0fr' : '1fr';
                                                    $el.textContent = isOpen ? 'Listen' : 'Hide';
                                                "
                                                class="text-sm text-blue-600 hover:underline dark:text-blue-400"
                                            >Listen</button>
                                            <div class="grid transition-[grid-template-rows] duration-200 ease-out" style="grid-template-rows: 0fr;">
                                                <div class="overflow-hidden min-h-0 mt-1">
                                                    <audio controls preload="metadata" class="max-w-[220px]">
                                                        <source src="{{ $pitchFileUrl }}">
                                                    </audio>
                                                </div>
                                            </div>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge color="zinc" size="sm">{{ $pitchFile->voicePart->name }}</flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <div class="max-w-[420px] whitespace-normal break-words text-zinc-500">
                                            {{ $pitchFile->description ?? '—' }}
                                        </div>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost">Close</flux:button>
                </flux:modal.close>
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

                @if ($version->application_type === \App\Enums\ApplicationType::EApplication)
                    <div class="space-y-3">
                        @if ($writeActionsBlocked)
                            <flux:text size="sm" class="text-zinc-500">
                                Signature status can't be changed right now — see the notice at the top of the page.
                            </flux:text>
                        @endif
                        <flux:checkbox
                            :checked="$candidate->application_candidate_signed_at !== null"
                            :disabled="$writeActionsBlocked"
                            wire:click="toggleApplicationCandidateSigned"
                            label="I have signed"
                        />
                        <flux:checkbox
                            :checked="$candidate->application_parent_signed_at !== null"
                            :disabled="$writeActionsBlocked"
                            wire:click="toggleApplicationParentSigned"
                            label="My parent/guardian has reviewed and approved"
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

    {{-- Edit Voice Part modal --}}
    <flux:modal name="edit-voice-part" class="w-full max-w-md">
        <div class="space-y-6">
            <flux:heading>Edit Voice Part</flux:heading>
            <flux:text size="sm" class="text-zinc-500">You are auditioning as a {{ $candidate->voicePart->name ?? 'voice part' }}.</flux:text>

            <flux:field>
                <flux:label>Voice Part</flux:label>
                <flux:select wire:model="edit_voice_part_id" placeholder="Select a voice part...">
                    @foreach ($version->availableVoiceParts() as $voicePart)
                        <flux:select.option value="{{ $voicePart->id }}">{{ $voicePart->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="edit_voice_part_id" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="saveVoicePart">Save</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Edit Program Name modal --}}
    <flux:modal name="edit-program-name" class="w-full max-w-md">
        <div class="space-y-6">
            <flux:heading>Edit Program Name</flux:heading>
            <flux:text size="sm" class="text-zinc-500">
                How your name appears in the program. Required for registration.
            </flux:text>

            <flux:field>
                <flux:label>Program Name</flux:label>
                <flux:input wire:model="edit_program_name" placeholder="e.g. Jane Smith" />
                <flux:error name="edit_program_name" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="saveProgramName">Save</flux:button>
            </div>
        </div>
    </flux:modal>

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

    {{-- Spotlight tour, same approach as Events\Show/VersionDashboard's
         (resources/views/livewire/events/show.blade.php,
         resources/views/livewire/registrations/version-dashboard.blade.php)
         — a persistent "Take a tour" button covering this page's cards. No
         tabs here (unlike Events\Show), so the simpler resolveEl()-only
         engine applies, same as VersionDashboard's own copy. --}}
    <button type="button" id="tour-dismiss-trigger" wire:click="dismissOrientation" class="hidden" aria-hidden="true" tabindex="-1"></button>

    <div id="tour-scrim" class="hidden fixed inset-0 z-[59]"></div>
    <div id="tour-cutout" class="hidden fixed z-[60] rounded-lg pointer-events-none transition-[top,left,width,height] duration-300 ease-out"></div>
    <div
        id="tour-card"
        class="hidden fixed z-[61] w-72 max-w-[calc(100vw-2rem)] bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg shadow-xl p-4 transition-[top,left] duration-300 ease-out"
        role="dialog" aria-modal="true" aria-labelledby="tour-title" aria-describedby="tour-body"
    >
        <div class="h-1 rounded-full bg-zinc-100 dark:bg-zinc-700 overflow-hidden mb-3">
            <div id="tour-progress" class="h-full bg-orange-600 dark:bg-orange-400 rounded-full transition-[width] duration-200"></div>
        </div>
        <div id="tour-stepcount" class="text-[11px] font-semibold uppercase tracking-wide text-orange-600 dark:text-orange-400 mb-1"></div>
        <h3 id="tour-title" class="text-sm font-semibold text-zinc-800 dark:text-zinc-100 mb-1"></h3>
        <p id="tour-body" class="text-sm text-zinc-500 dark:text-zinc-400 mb-3"></p>
        <div class="flex items-center justify-between gap-2">
            <button type="button" id="tour-skip" class="text-sm text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200">Skip tour</button>
            <div class="flex gap-2">
                <button type="button" id="tour-prev" class="text-sm font-medium px-3 py-1.5 rounded-md border border-zinc-200 dark:border-zinc-700 text-zinc-700 dark:text-zinc-200 hover:bg-zinc-50 dark:hover:bg-zinc-700 disabled:opacity-40">Back</button>
                <button type="button" id="tour-next" class="text-sm font-medium px-3 py-1.5 rounded-md border border-orange-600 bg-orange-600 text-white hover:brightness-110 dark:border-orange-400 dark:bg-orange-400 dark:text-zinc-900">Next</button>
            </div>
        </div>
    </div>

    <style>
        #tour-cutout { box-shadow: 0 0 0 9999px rgba(15, 13, 12, 0.6); }
        :root[data-theme="dark"] #tour-cutout { box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.72); }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) #tour-cutout { box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.72); }
        }
        #tour-cutout::after {
            content: "";
            position: absolute;
            inset: -4px;
            border-radius: 11px;
            border: 2px solid rgb(234 88 12);
            box-shadow: 0 0 0 4px rgba(234, 88, 12, 0.5);
            animation: tour-pulse 1.8s ease-in-out infinite;
        }
        @keyframes tour-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.45; }
        }
        @media (prefers-reduced-motion: reduce) {
            #tour-cutout, #tour-card { transition: none !important; }
            #tour-cutout::after { animation: none !important; }
        }
    </style>

    <script>
        (function () {
            var steps = [
                { ids: ['tour-status-badge'], title: 'Status', body: 'Where you sit in the registration lifecycle for this Event — Eligible, Pending, Registered, or Withdrawn.' },
                { ids: ['tour-pitch-files'], title: 'Pitch Files', body: 'Reference audio and PDFs for your voice part — the same library your teacher can browse.' },
                { ids: ['tour-candidate-requirements'], title: 'Candidate Requirements', body: "What's needed before your teacher can register you. Anything not yet done links to the page where you can complete it." },
                { ids: ['tour-registration-card'], title: 'Registration', body: 'Your voice part and program name — edit either one, or withdraw from this Event entirely.' },
                { ids: ['tour-application-card'], title: 'Application', body: 'View your application, and — if this Event uses e-signatures — sign it yourself.' },
                { ids: ['tour-recordings-card'], title: 'Recordings', body: 'Upload audition recordings for a remote audition Event. Once your teacher approves one, you can only replay it.' },
                { ids: ['tour-payment-card'], title: 'Payment', body: "Pay any fees your teacher has opted you into electronically, and see your payment history." }
            ];

            var activeSteps = [];
            var current = -1;
            var running = false;
            var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            var raf = null;

            var scrim = document.getElementById('tour-scrim');
            var cutout = document.getElementById('tour-cutout');
            var card = document.getElementById('tour-card');
            var startBtn = document.getElementById('tour-start');
            var dismissTrigger = document.getElementById('tour-dismiss-trigger');
            var prevBtn = document.getElementById('tour-prev');
            var nextBtn = document.getElementById('tour-next');
            var skipBtn = document.getElementById('tour-skip');
            var titleEl = document.getElementById('tour-title');
            var bodyEl = document.getElementById('tour-body');
            var stepCountEl = document.getElementById('tour-stepcount');
            var progressEl = document.getElementById('tour-progress');

            if (!startBtn || !scrim || !cutout || !card) return;

            function resolveEl(ids) {
                for (var i = 0; i < ids.length; i++) {
                    var el = document.getElementById(ids[i]);
                    if (el && el.offsetParent !== null) return el;
                }
                return null;
            }

            function start() {
                activeSteps = steps.filter(function (s) { return resolveEl(s.ids) !== null; });
                if (activeSteps.length === 0) return;

                running = true;
                current = 0;
                scrim.classList.remove('hidden');
                cutout.classList.remove('hidden');
                card.classList.remove('hidden');
                document.addEventListener('keydown', onKeydown);
                window.addEventListener('resize', onReposition);
                window.addEventListener('scroll', onReposition, true);
                render();
            }

            function end() {
                running = false;
                scrim.classList.add('hidden');
                cutout.classList.add('hidden');
                card.classList.add('hidden');
                document.removeEventListener('keydown', onKeydown);
                window.removeEventListener('resize', onReposition);
                window.removeEventListener('scroll', onReposition, true);
                if (dismissTrigger) dismissTrigger.click();
                startBtn.focus();
            }

            function go(delta) {
                var target = current + delta;
                if (target < 0) return;
                if (target >= activeSteps.length) { end(); return; }
                current = target;
                render();
            }

            function render() {
                var step = activeSteps[current];
                var el = resolveEl(step.ids);
                if (!el) { go(1); return; }

                titleEl.textContent = step.title;
                bodyEl.textContent = step.body;
                stepCountEl.textContent = 'Step ' + (current + 1) + ' of ' + activeSteps.length;
                progressEl.style.width = (((current + 1) / activeSteps.length) * 100) + '%';
                prevBtn.disabled = current === 0;
                nextBtn.textContent = current === activeSteps.length - 1 ? 'Finish' : 'Next';

                el.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });

                window.setTimeout(function () { position(el); }, reduceMotion ? 0 : 260);
                nextBtn.focus();
            }

            function position(el) {
                var pad = 6;
                var r = el.getBoundingClientRect();

                cutout.style.top = (r.top - pad) + 'px';
                cutout.style.left = (r.left - pad) + 'px';
                cutout.style.width = (r.width + pad * 2) + 'px';
                cutout.style.height = (r.height + pad * 2) + 'px';

                var cardW = card.offsetWidth || 288;
                var cardH = card.offsetHeight || 160;
                var margin = 14;
                var vw = window.innerWidth;
                var vh = window.innerHeight;

                var top = r.bottom + margin;
                if (top + cardH > vh) {
                    top = r.top - cardH - margin;
                    if (top < 8) top = Math.max(8, Math.min(vh - cardH - 8, r.top));
                }

                var left = r.left;
                if (left + cardW > vw - 8) left = vw - cardW - 8;
                if (left < 8) left = 8;

                card.style.top = top + 'px';
                card.style.left = left + 'px';
            }

            function onReposition() {
                if (!running) return;
                if (raf) cancelAnimationFrame(raf);
                raf = requestAnimationFrame(function () {
                    var el = resolveEl(activeSteps[current].ids);
                    if (el) position(el);
                });
            }

            function onKeydown(e) {
                if (e.key === 'Escape') { end(); return; }
                if (e.key === 'ArrowRight' || e.key === 'Enter') { go(1); return; }
                if (e.key === 'ArrowLeft') { go(-1); return; }
            }

            startBtn.addEventListener('click', start);
            nextBtn.addEventListener('click', function () { go(1); });
            prevBtn.addEventListener('click', function () { go(-1); });
            skipBtn.addEventListener('click', end);

            if (startBtn.dataset.autoStart === '1') start();
        })();
    </script>
</div>
