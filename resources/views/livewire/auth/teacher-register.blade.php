<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2 text-center">
        <flux:heading size="xl">Teacher / Event Manager Registration</flux:heading>
        <flux:subheading>TheDirectorsRoom.com</flux:subheading>
    </div>

    <flux:callout color="blue" icon="information-circle" heading="Are you a student?">
        <flux:callout.text>
            This form is for teachers and event managers. Students should register at
            <flux:callout.link :href="route('sfdi.register')" wire:navigate>StudentFolder.info</flux:callout.link>.
        </flux:callout.text>
    </flux:callout>

    <form wire:submit="register" class="flex flex-col gap-6">
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

            <flux:select wire:model="pronoun_id" label="Pronouns">
                @foreach ($pronouns as $pronoun)
                    <flux:select.option value="{{ $pronoun->id }}">{{ $pronoun->description }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <flux:input wire:model="first_name" label="First name" required autofocus />
            <flux:input wire:model="middle_name" label="Middle name (optional)" />
            <flux:input wire:model="last_name" label="Last name" required />
        </div>

        <flux:input wire:model="suffix_name" label="Suffix (optional)" placeholder="Jr., Sr., III, etc." />

        <flux:input wire:model="email" label="Email address" type="email" required autocomplete="email" />

        <flux:input wire:model="cell_phone" label="Cell phone" type="tel" required autocomplete="tel" />

        <flux:input
            wire:model="password"
            label="Password"
            type="password"
            required
            autocomplete="new-password"
            viewable
        />

        <flux:input
            wire:model="password_confirmation"
            label="Confirm password"
            type="password"
            required
            autocomplete="new-password"
            viewable
        />

        <flux:button type="submit" variant="primary">
            Register
        </flux:button>
    </form>

    <flux:separator text="or" />

    <div class="text-center text-sm">
        Already have an account?
        <flux:link :href="route('login')" wire:navigate>Log in</flux:link>
    </div>
</div>
