<div class="flex flex-col gap-6">
    <flux:subheading>Which school, studio, or program do you teach at?</flux:subheading>

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
            autofocus
        />
    </div>

    <flux:input wire:model.live.debounce.300ms="school_search" label="School or studio name" placeholder="Start typing to search..." />

    @if ($schoolSuggestions->isNotEmpty())
        <div class="flex flex-col gap-2">
            <flux:text>{{ $school_search !== '' ? 'Did you mean one of these?' : 'Schools at this zip code:' }}</flux:text>
            @foreach ($schoolSuggestions as $match)
                <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <div>
                        <flux:text class="font-medium">{{ $match['school']->name }}</flux:text>
                        <flux:text size="sm" class="text-zinc-500">{{ $match['school']->city }}, {{ $match['school']->zip_code }} &middot; {{ $match['school']->type->label() }}</flux:text>
                    </div>
                    <flux:button size="sm" wire:click="selectSchool({{ $match['school']->id }})">This is my school</flux:button>
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

            <flux:button variant="primary" wire:click="createSchool">Create &amp; continue</flux:button>
        </div>
    @endif
</div>
