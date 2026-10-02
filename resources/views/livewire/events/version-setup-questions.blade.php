<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.readiness', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Setup questions</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">A few questions about {{ $version->name }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 max-w-2xl">
                Your answers set up the event and trim the setup checklist to what applies to you. Skip anything you're not sure of —
                it stays on the checklist, and you can come back to these questions any time.
            </flux:text>
        </div>
        <flux:button size="sm" variant="ghost" href="{{ route('events.versions.readiness', $version) }}" wire:navigate class="shrink-0">Skip for now</flux:button>
    </div>

    <form wire:submit="save" class="space-y-8 max-w-3xl">
        {{-- Part 1 --}}
        <flux:card class="space-y-8">
            <div>
                <flux:heading size="lg">The shape of your event</flux:heading>
                <flux:text size="sm" class="text-zinc-500">These decide which setup steps apply.</flux:text>
            </div>

            {{-- Q1 --}}
            <div class="space-y-4">
                <flux:radio.group wire:model.live="audition_type" label="How do students audition?">
                    <flux:radio value="remote" label="They send recordings" />
                    <flux:radio value="in_person" label="In person, at a scheduled time" />
                </flux:radio.group>
                @if ($audition_type !== '')
                    <flux:button size="xs" variant="ghost" wire:click="$set('audition_type', '')">Clear answer</flux:button>
                @endif

                @if ($audition_type === 'remote')
                    <div class="pl-4 border-l-2 border-zinc-200 dark:border-zinc-700 space-y-4">
                        <flux:radio.group wire:model="upload_type" label="Audio or video?" variant="segmented">
                            <flux:radio value="audio" label="Audio" />
                            <flux:radio value="video" label="Video" />
                        </flux:radio.group>

                        @if ($existingRecordings->isNotEmpty())
                            <flux:text size="sm">
                                <span class="font-medium text-zinc-700 dark:text-zinc-300">Recordings already listed:</span>
                                {{ $existingRecordings->implode(', ') }}
                            </flux:text>
                        @else
                            <flux:field>
                                <flux:label>What will each student record? <span class="text-zinc-500 font-normal">(optional)</span></flux:label>
                                <flux:description>One per line, in order — for example "Scales", "Solo", "Sight-reading". You can add or change these later.</flux:description>
                                <flux:textarea wire:model="recording_names" rows="3" />
                                <flux:error name="recording_names" />
                            </flux:field>
                        @endif
                    </div>
                @elseif ($audition_type === 'in_person')
                    <div class="pl-4 border-l-2 border-zinc-200 dark:border-zinc-700">
                        <flux:field class="max-w-xs">
                            <flux:label>How long is each audition, in minutes?</flux:label>
                            <flux:input type="number" min="5" max="120" wire:model="audition_timeslot" />
                            <flux:error name="audition_timeslot" />
                        </flux:field>
                    </div>
                @endif
            </div>

            {{-- Q2 --}}
            <div class="space-y-2">
                <flux:radio.group wire:model.live="application_type" label="How do students submit their application?">
                    <flux:radio value="pdf" label="Printed and signed on paper (PDF)" />
                    <flux:radio value="eapplication" label="Signed online in StudentFolder" />
                </flux:radio.group>
                @if ($application_type !== '')
                    <flux:button size="xs" variant="ghost" wire:click="$set('application_type', '')">Clear answer</flux:button>
                @endif
            </div>

            {{-- Q2b --}}
            <div class="space-y-2">
                <flux:radio.group wire:model="mail_required" label="Must teachers send physical materials through the mail to complete registration?" description="For example signed paper applications, checks, membership cards, or forms — even if the application itself is online." variant="segmented">
                    <flux:radio value="yes" label="Yes" />
                    <flux:radio value="no" label="No" />
                </flux:radio.group>
                @if ($mail_required !== '')
                    <flux:button size="xs" variant="ghost" wire:click="$set('mail_required', '')">Clear answer</flux:button>
                @endif
            </div>

            {{-- Q3 --}}
            <div class="space-y-4">
                <flux:radio.group wire:model.live="membership_card" label="Must teachers hold a current membership card?" variant="segmented">
                    <flux:radio value="yes" label="Yes" />
                    <flux:radio value="no" label="No" />
                </flux:radio.group>
                @if ($membership_card === 'yes')
                    <div class="pl-4 border-l-2 border-zinc-200 dark:border-zinc-700">
                        <flux:field class="max-w-xs">
                            <flux:label>Valid through <span class="text-zinc-500 font-normal">(optional)</span></flux:label>
                            <flux:input type="date" wire:model="membership_valid_thru" />
                            <flux:description>Leave blank if any current membership is accepted.</flux:description>
                            <flux:error name="membership_valid_thru" />
                        </flux:field>
                    </div>
                @endif
                @if ($membership_card !== '')
                    <flux:button size="xs" variant="ghost" wire:click="$set('membership_card', '')">Clear answer</flux:button>
                @endif
            </div>

            {{-- Q4a --}}
            <div class="space-y-2">
                <flux:radio.group wire:model="teacher_payments" label="Can teachers pay their balance online?" description="At the end of registration, each teacher settles what they owe for all of their students. Online payment uses your Square or PayPal account; Square setup must be done by the account owner.">
                    @foreach ($teacherPaymentOptions as $option)
                        <flux:radio value="{{ $option->value }}" label="{{ $option->label() }}" />
                    @endforeach
                </flux:radio.group>
                @if ($teacher_payments !== '')
                    <flux:button size="xs" variant="ghost" wire:click="$set('teacher_payments', '')">Clear answer</flux:button>
                @endif
            </div>

            {{-- Q4b --}}
            <div class="space-y-2">
                <flux:radio.group wire:model="student_payments" label="Can teachers let their students pay online through StudentFolder?" description="Each teacher decides for their own students. Student payments are optional and are credited toward the teacher's balance." variant="segmented">
                    <flux:radio value="yes" label="Yes" />
                    <flux:radio value="no" label="No" />
                </flux:radio.group>
                @if ($student_payments !== '')
                    <flux:button size="xs" variant="ghost" wire:click="$set('student_payments', '')">Clear answer</flux:button>
                @endif
            </div>

            {{-- Q5 --}}
            @if ($existingEnsembles->isEmpty())
                <flux:field>
                    <flux:label>What ensembles will students be placed into?</flux:label>
                    <flux:description>One per line — for example "Mixed Chorus", "Treble Choir". You'll choose each ensemble's grades and voice parts on the checklist.</flux:description>
                    <flux:textarea wire:model="ensemble_names" rows="3" />
                    <flux:error name="ensemble_names" />
                </flux:field>
            @else
                <flux:text size="sm">
                    <span class="font-medium text-zinc-700 dark:text-zinc-300">Ensembles:</span>
                    {{ $existingEnsembles->implode(', ') }}
                </flux:text>
            @endif

            {{-- Q6 --}}
            <flux:field class="max-w-xs">
                <flux:label>How many judges score each student?</flux:label>
                <flux:input type="number" min="1" max="20" wire:model="judge_count" placeholder="e.g. 1" />
                <flux:error name="judge_count" />
            </flux:field>
        </flux:card>

        {{-- Part 2 --}}
        <flux:card class="space-y-6">
            <div>
                <flux:heading size="lg">Quick yes or no</flux:heading>
                <flux:text size="sm" class="text-zinc-500">
                    Optional features. "No" marks a step as not needed; "Yes" leaves it on your checklist to set up.
                </flux:text>
            </div>

            @foreach ($optionalQuestions as $q)
                <div wire:key="optional-{{ $q['field'] }}" class="space-y-2">
                    @if ($q['existing'])
                        <flux:heading size="sm">{{ $q['question'] }}</flux:heading>
                        <flux:text size="sm">
                            Already set up — {{ $q['existing'] }}.
                            @if ($q['url'])
                                <flux:link href="{{ $q['url'] }}" wire:navigate>Review</flux:link>
                            @endif
                        </flux:text>
                    @else
                        <flux:radio.group wire:model="optional.{{ $q['field'] }}" label="{{ $q['question'] }}" description="{{ $q['help'] }}" variant="segmented">
                            <flux:radio value="yes" label="Yes" />
                            <flux:radio value="no" label="No" />
                        </flux:radio.group>
                        @if (($optional[$q['field']] ?? '') !== '')
                            <flux:button size="xs" variant="ghost" wire:click="$set('optional.{{ $q['field'] }}', '')">Clear answer</flux:button>
                        @endif
                    @endif
                </div>
            @endforeach
        </flux:card>

        @if ($errors->any())
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.text>Please correct the errors above.</flux:callout.text>
            </flux:callout>
        @endif

        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary">Save and go to checklist</flux:button>
            <flux:button variant="ghost" href="{{ route('events.versions.readiness', $version) }}" wire:navigate>Skip for now</flux:button>
        </div>
    </form>
</div>
