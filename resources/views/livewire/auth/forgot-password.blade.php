<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2 text-center">
        <flux:heading size="xl">Forgot password</flux:heading>
        <flux:subheading>Enter your email and we'll send you a password reset link</flux:subheading>
    </div>

    @if ($status)
        <flux:callout variant="success" heading="Check your email" text="If an account exists for that address, we've sent a password reset link to it." />
    @else
        <form wire:submit="sendResetLink" class="flex flex-col gap-6">
            <flux:input
                wire:model="email"
                label="Email address"
                type="email"
                required
                autofocus
                autocomplete="email"
            />

            <flux:button type="submit" variant="primary">
                Email password reset link
            </flux:button>
        </form>
    @endif

    <div class="text-center text-sm">
        <flux:link :href="route('login')" wire:navigate>Back to log in</flux:link>
    </div>
</div>
