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

    <div class="flex flex-col gap-3">
        <a href="{{ route('social.redirect', 'google') }}"
           class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-zinc-200 bg-white px-4 py-2 text-sm font-medium text-zinc-700 shadow-sm hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700">
            <svg class="h-4 w-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/>
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
            </svg>
            Register using Google
        </a>

        <a href="{{ route('social.redirect', 'facebook') }}"
           class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-zinc-200 bg-white px-4 py-2 text-sm font-medium text-zinc-700 shadow-sm hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700">
            <svg class="h-4 w-4 text-[#1877F2]" fill="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
            </svg>
            Register using Facebook
        </a>
    </div>

    <flux:separator text="or register manually" />

    <form wire:submit="register" class="flex flex-col gap-6">
        <div class="grid grid-cols-1 gap-4">
            <flux:select wire:model="honorific" label="Honorific (optional)" placeholder="Select...">
                <flux:select.option value="Mr.">Mr.</flux:select.option>
                <flux:select.option value="Mrs.">Mrs.</flux:select.option>
                <flux:select.option value="Ms.">Ms.</flux:select.option>
                <flux:select.option value="Mx.">Mx.</flux:select.option>
                <flux:select.option value="Dr.">Dr.</flux:select.option>
                <flux:select.option value="Prof.">Prof.</flux:select.option>
                <flux:select.option value="Rev.">Rev.</flux:select.option>
            </flux:select>

            <flux:select wire:model="pronoun_id" label="Pronouns" placeholder="Select pronoun...">
                @foreach ($pronouns as $pronoun)
                    <flux:select.option value="{{ $pronoun->id }}">{{ $pronoun->description }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="grid grid-cols-1 gap-4">
            <flux:input wire:model="first_name" label="First name" required autofocus />
            <flux:input wire:model="middle_name" label="Middle name (optional)" />
            <flux:input wire:model="last_name" label="Last name" required />
        </div>

        <flux:input wire:model="suffix_name" label="Suffix (optional)" placeholder="Jr., Sr., III, etc." />

        <flux:separator />

        <flux:input wire:model.live.debounce.500ms="email" label="Email address" type="email" required autocomplete="email" />

        @if ($email !== '' && ! $errors->has('email'))
            <flux:text size="sm" color="green">
                A verification email will be sent to this address.
            </flux:text>
        @endif

        <flux:input
            wire:model="cell_phone"
            label="Cell phone"
            type="tel"
            required
            autocomplete="tel"
            mask:dynamic="$input.replace(/\D/g, '').length > 10 ? '(999) 999-9999 x9999' : '(999) 999-9999'"
        />

        <flux:separator />

        <flux:input
            wire:model.live.debounce.500ms="password"
            label="Password"
            type="password"
            required
            autocomplete="new-password"
            viewable
        />

        <flux:input
            wire:model.live.debounce.500ms="password_confirmation"
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

    <flux:separator />

    <div class="text-center text-sm">
        Already have an account?
        <flux:link :href="route('login')" wire:navigate>Log in</flux:link>
    </div>
</div>
