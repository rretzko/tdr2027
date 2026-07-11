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
                @php $done = ($def['check'])($candidate); @endphp
                <span class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-medium
                    {{ $done ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400' }}">
                    @if ($done)
                        <flux:icon.check-circle variant="micro" />
                    @else
                        <flux:icon.x-circle variant="micro" />
                    @endif
                    {{ $def['label'] }}
                </span>
            @endforeach
        </div>
    </flux:card>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Left column: Program name + Student info --}}
        <div class="space-y-6">

            {{-- Program name --}}
            <flux:card>
                <flux:heading size="sm" class="mb-3">Program Name</flux:heading>
                <flux:text size="sm" class="text-zinc-500 mb-3">
                    How this student's name appears in the program. Required for registration.
                </flux:text>

                <div class="flex gap-2 items-end">
                    <flux:field class="flex-1">
                        <flux:label>Program Name</flux:label>
                        <flux:input wire:model="program_name" placeholder="e.g. Jane Smith" />
                        <flux:error name="program_name" />
                    </flux:field>
                    <flux:button wire:click="saveProgramName">Save</flux:button>
                </div>
            </flux:card>

            {{-- Student info --}}
            <flux:card>
                <flux:heading size="sm" class="mb-3">Student</flux:heading>

                <div class="space-y-2 text-sm">
                    <div class="flex gap-2">
                        <span class="text-zinc-500 w-28 shrink-0">Name</span>
                        <span>{{ $candidate->student->user->first_name }} {{ $candidate->student->user->last_name }}</span>
                    </div>

                    @if ($candidate->voicePart)
                        <div class="flex gap-2">
                            <span class="text-zinc-500 w-28 shrink-0">Voice Part</span>
                            <span>{{ $candidate->voicePart->name }}</span>
                        </div>
                    @endif

                    @if ($candidate->student->birthday !== null)
                        <div class="flex gap-2">
                            <span class="text-zinc-500 w-28 shrink-0">Birthday</span>
                            @php $bday = $candidate->student->getRawOriginal('birthday'); @endphp
                            <span>{{ $bday ? \Carbon\Carbon::parse((string) $bday)->format('M j, Y') : '—' }}</span>
                        </div>
                    @endif

                    @if ($candidate->student->height !== null)
                        <div class="flex gap-2">
                            <span class="text-zinc-500 w-28 shrink-0">Height</span>
                            <span>{{ $candidate->student->height }}"</span>
                        </div>
                    @endif

                    @if ($candidate->student->homeAddress !== null)
                        <div class="flex gap-2">
                            <span class="text-zinc-500 w-28 shrink-0">Address</span>
                            <span>{{ $candidate->student->homeAddress->formatted }}</span>
                        </div>
                    @endif
                </div>
            </flux:card>

        </div>

        {{-- Right column: Emergency contact --}}
        <div class="space-y-6">

            {{-- Existing emergency contacts --}}
            @if ($candidate->student->emergencyContacts->isNotEmpty())
                <flux:card>
                    <flux:heading size="sm" class="mb-3">Emergency Contacts</flux:heading>
                    <div class="space-y-3">
                        @foreach ($candidate->student->emergencyContacts as $ec)
                            <div class="text-sm border-b border-zinc-100 dark:border-zinc-800 pb-3 last:border-0 last:pb-0">
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
                        @endforeach
                    </div>
                </flux:card>
            @endif

            {{-- Add emergency contact form --}}
            @if ((bool) $version->emergency_contact_name)
                <flux:card>
                    <flux:heading size="sm" class="mb-3">Add Emergency Contact</flux:heading>

                    <div class="space-y-4">
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
                            <flux:label>Cell Phone</flux:label>
                            <flux:input wire:model="ec_cell_phone" placeholder="(555) 000-0000" />
                            <flux:error name="ec_cell_phone" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Home Phone</flux:label>
                            <flux:input wire:model="ec_home_phone" placeholder="(555) 000-0000" />
                            <flux:error name="ec_home_phone" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Email</flux:label>
                            <flux:input wire:model="ec_email" type="email" placeholder="email@example.com" />
                            <flux:error name="ec_email" />
                        </flux:field>

                        @if ($errors->hasAny(['ec_name', 'ec_relationship', 'ec_cell_phone', 'ec_home_phone', 'ec_email']))
                            <flux:callout variant="danger" icon="exclamation-triangle">
                                <flux:callout.text>Please correct the errors above.</flux:callout.text>
                            </flux:callout>
                        @endif

                        <flux:button variant="primary" wire:click="saveEmergencyContact">
                            Save Emergency Contact
                        </flux:button>
                    </div>
                </flux:card>
            @endif

            {{-- Candidate Application --}}
            @if ($version->candidateApplication?->isPublished())
                <flux:card>
                    <flux:heading size="sm" class="mb-3">Candidate Application</flux:heading>

                    @if ($version->application_type === \App\Enums\ApplicationType::Pdf)
                        <flux:button
                            variant="{{ $candidate->application_certified_at !== null ? 'primary' : 'filled' }}"
                            wire:click="toggleApplicationCertified"
                            wire:confirm="{{ $candidate->application_certified_at !== null ? 'Undo this certification?' : 'Certify that these signatures are present, complete, and have integrity?' }}"
                        >
                            {{ $candidate->application_certified_at !== null ? 'Certified — Undo' : 'Certify Signatures' }}
                        </flux:button>
                        @if ($candidate->application_certified_at !== null)
                            <flux:text size="sm" class="text-zinc-500 mt-2">
                                Certified by {{ $candidate->applicationCertifiedBy?->name }}
                                on {{ $candidate->application_certified_at->format('M j, Y g:ia') }}.
                            </flux:text>
                        @endif
                    @else
                        <div class="flex flex-wrap gap-2">
                            <flux:button
                                size="sm"
                                variant="{{ $candidate->application_candidate_signed_at !== null ? 'primary' : 'filled' }}"
                                wire:click="toggleApplicationCandidateSigned"
                            >
                                {{ $candidate->application_candidate_signed_at !== null ? 'Candidate Signed — Undo' : 'Mark Candidate Signed' }}
                            </flux:button>
                            <flux:button
                                size="sm"
                                variant="{{ $candidate->application_parent_signed_at !== null ? 'primary' : 'filled' }}"
                                wire:click="toggleApplicationParentSigned"
                            >
                                {{ $candidate->application_parent_signed_at !== null ? 'Parent Signed — Undo' : 'Mark Parent Signed' }}
                            </flux:button>
                        </div>
                    @endif

                    <flux:button
                        class="mt-4"
                        size="sm" variant="ghost" icon="arrow-down-tray"
                        :href="route('registrations.candidate.application-pdf', [$version, $candidate])"
                    >
                        Download PDF{{ $version->application_type === \App\Enums\ApplicationType::EApplication ? ' (optional copy)' : '' }}
                    </flux:button>
                </flux:card>
            @endif

        </div>
    </div>
</div>
