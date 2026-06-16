<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2 text-center">
        <flux:heading size="xl">Complete Your Profile</flux:heading>
        <flux:subheading>
            A few more details are needed to finish setting up your account.
        </flux:subheading>
    </div>

    <form wire:submit="save" class="flex flex-col gap-6">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <flux:select wire:model="honorific" label="Honorific (optional)" placeholder="Select...">
                <flux:select.option value="Mr.">Mr.</flux:select.option>
                <flux:select.option value="Mrs.">Mrs.</flux:select.option>
                <flux:select.option value="Ms.">Ms.</flux:select.option>
                <flux:select.option value="Mx.">Mx.</flux:select.option>
                <flux:select.option value="Dr.">Dr.</flux:select.option>
                <flux:select.option value="Prof.">Prof.</flux:select.option>
                <flux:select.option value="Rev.">Rev.</flux:select.option>
            </flux:select>

            <flux:select wire:model="pronoun_id" label="Pronouns" placeholder="Select pronoun..." autofocus>
                @foreach ($pronouns as $pronoun)
                    <flux:select.option value="{{ $pronoun->id }}">{{ $pronoun->description }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="grid grid-cols-1 gap-4">
            <flux:input wire:model="first_name" label="First name" required />
            <flux:input wire:model="middle_name" label="Middle name (optional)" />
            <flux:input wire:model="last_name" label="Last name" required />
        </div>

        <flux:input wire:model="suffix_name" label="Suffix (optional)" placeholder="Jr., Sr., III, etc." />

        <flux:separator />

        <flux:input
            wire:model="email"
            label="Email address"
            type="email"
            required
            autocomplete="email"
            description="Used for notifications and password recovery. Verification required if changed."
        />

        <flux:separator />

        <flux:callout color="blue" icon="information-circle" heading="Set a password (optional)">
            <flux:callout.text>
                You can also log in with your cell phone and a password. Leave blank to continue using social login only.
            </flux:callout.text>
        </flux:callout>

        <flux:input
            wire:model.live.debounce.500ms="password"
            label="Password"
            type="password"
            autocomplete="new-password"
            viewable
        />

        <flux:input
            wire:model.live.debounce.500ms="password_confirmation"
            label="Confirm password"
            type="password"
            autocomplete="new-password"
            viewable
        />

        <flux:button type="submit" variant="primary">Save &amp; Continue</flux:button>
    </form>
</div>
