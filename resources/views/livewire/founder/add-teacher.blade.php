<div>
    <div class="mb-6">
        <flux:heading size="xl">Add Teacher</flux:heading>
        <flux:subheading>Create a user, mark them as a teacher, link them to a school, and verify their emails.</flux:subheading>
    </div>

    @if ($createdUser === null)
        <div class="flex flex-col gap-8 max-w-2xl">
            <div class="flex flex-col gap-4">
                <flux:heading size="lg">Teacher details</flux:heading>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:input wire:model="honorific" label="Honorific (optional)" placeholder="e.g. Mr., Ms., Dr." />
                    <flux:select wire:model="pronoun_id" label="Pronouns">
                        <flux:select.option value="">Select...</flux:select.option>
                        @foreach ($pronouns as $pronoun)
                            <flux:select.option value="{{ $pronoun->id }}">{{ $pronoun->description }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <flux:input wire:model="first_name" label="First name" required />
                    <flux:input wire:model="middle_name" label="Middle name (optional)" />
                    <flux:input wire:model="last_name" label="Last name" required />
                </div>

                <flux:input wire:model="suffix_name" label="Suffix (optional)" placeholder="e.g. Jr., III" />

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:input wire:model="email" type="email" label="Account email" required />
                    <flux:input wire:model="cell_phone" label="Cell phone (optional)" placeholder="e.g. 6095551234" />
                </div>
            </div>

            <flux:separator />

            <div class="flex flex-col gap-4">
                <flux:heading size="lg">School or studio</flux:heading>

                @if ($selectedSchoolId !== null)
                    <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <flux:text class="font-medium">{{ $selectedSchoolName }}</flux:text>
                        <flux:button size="sm" variant="ghost" wire:click="clearSelectedSchool">Change</flux:button>
                    </div>
                @else
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <flux:select wire:model.live="geostate_id" label="State">
                            <flux:select.option value="">Select a state...</flux:select.option>
                            @foreach ($geostates as $geostate)
                                <flux:select.option value="{{ $geostate->id }}">{{ $geostate->name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input
                            wire:model.live.blur="zip_code"
                            x-on:input="$event.target.value.length === 5 && $wire.set('zip_code', $event.target.value)"
                            label="Zip code (optional)"
                            placeholder="e.g. 08901"
                            inputmode="numeric"
                            maxlength="5"
                        />
                    </div>

                    <flux:input wire:model.live.debounce.300ms="school_search" label="School or studio name" placeholder="Start typing to search..." />

                    @error('selectedSchoolId')
                        <flux:error>{{ $message }}</flux:error>
                    @enderror

                    @if ($schoolSuggestions->isNotEmpty())
                        <div class="flex flex-col gap-2">
                            <flux:text>{{ $school_search !== '' ? 'Did you mean one of these?' : 'Schools at this zip code:' }}</flux:text>
                            @foreach ($schoolSuggestions as $match)
                                <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                    <div>
                                        <flux:text class="font-medium">{{ $match['school']->name }}</flux:text>
                                        <flux:text size="sm" class="text-zinc-500">{{ $match['school']->city }}, {{ $match['school']->zip_code }} &middot; {{ $match['school']->type->label() }}</flux:text>
                                    </div>
                                    <flux:button size="sm" wire:click="selectSchool({{ $match['school']->id }})">This is the school</flux:button>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if (($school_search !== '' || $zip_code !== '') && ! $creatingNewSchool)
                        <flux:button variant="ghost" wire:click="$set('creatingNewSchool', true)">
                            None of these — add a new school or studio
                        </flux:button>
                    @endif

                    @if ($creatingNewSchool)
                        <flux:separator />

                        <div class="flex flex-col gap-4">
                            <flux:radio.group wire:model="new_school_type" label="Type">
                                <flux:radio value="school" label="School" />
                                <flux:radio value="studio" label="Studio" />
                            </flux:radio.group>

                            <flux:input wire:model="new_school_name" label="Name" required />

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <flux:input wire:model="new_school_city" label="City" required />
                                <flux:input wire:model="new_school_zip_code" label="Zip code" required />
                            </div>

                            <flux:select wire:model="new_school_county_id" label="County" placeholder="Select a county...">
                                @foreach ($counties as $county)
                                    <flux:select.option value="{{ $county->id }}">{{ $county->name }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:button variant="primary" wire:click="createSchool">Create &amp; select</flux:button>
                        </div>
                    @endif
                @endif

                <flux:input wire:model="school_email" type="email" label="Teacher's school email (optional)" placeholder="e.g. teacher@school.edu" />
            </div>

            <div>
                <flux:button variant="primary" wire:click="createTeacher" wire:loading.attr="disabled">
                    Create Teacher
                </flux:button>
            </div>

            @if ($errors->any())
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>
                        <ul class="list-inside list-disc">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </flux:callout.text>
                </flux:callout>
            @endif
        </div>
    @else
        <div class="flex flex-col gap-6 max-w-2xl">
            <flux:callout variant="success" icon="check-circle">
                <flux:callout.text>
                    {{ $createdUser->first_name }} {{ $createdUser->last_name }} was created as a teacher at {{ $createdSchoolTeacher->school->name }}.
                </flux:callout.text>
            </flux:callout>

            <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="mb-3 flex items-center justify-between gap-4">
                    <div>
                        <flux:text class="font-medium">Account email</flux:text>
                        <flux:text size="sm" class="text-zinc-500">{{ $createdUser->email }}</flux:text>
                    </div>
                    @if ($createdUser->hasVerifiedEmail())
                        <flux:badge color="green">Verified</flux:badge>
                    @else
                        <flux:badge color="yellow">Pending</flux:badge>
                    @endif
                </div>

                @unless ($createdUser->hasVerifiedEmail())
                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" wire:click="verifyUserEmailNow">Mark verified now</flux:button>
                        <flux:button size="sm" variant="ghost" wire:click="sendUserVerificationEmail">Send verification email</flux:button>
                    </div>
                @endunless
            </div>

            @if ($createdSchoolTeacher->school_email)
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="mb-3 flex items-center justify-between gap-4">
                        <div>
                            <flux:text class="font-medium">School email</flux:text>
                            <flux:text size="sm" class="text-zinc-500">{{ $createdSchoolTeacher->school_email }}</flux:text>
                        </div>
                        @if ($createdSchoolTeacher->verified_at)
                            <flux:badge color="green">Verified</flux:badge>
                        @else
                            <flux:badge color="yellow">Pending</flux:badge>
                        @endif
                    </div>

                    @unless ($createdSchoolTeacher->verified_at)
                        <div class="flex flex-wrap gap-2">
                            <flux:button size="sm" wire:click="verifySchoolEmailNow">Mark verified now</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="sendSchoolVerificationEmail">Send verification email</flux:button>
                        </div>
                    @endunless
                </div>
            @endif

            <div>
                <flux:button variant="primary" wire:click="addAnother">Add another teacher</flux:button>
            </div>
        </div>
    @endif
</div>
