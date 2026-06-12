<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2 text-center">
        <flux:heading size="xl">Reset password</flux:heading>
        <flux:subheading>Enter your new password below</flux:subheading>
    </div>

    <form wire:submit="resetPassword" class="flex flex-col gap-6">
        <flux:input
            wire:model="email"
            label="Email address"
            type="email"
            required
            autofocus
            autocomplete="email"
        />

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
            Reset password
        </flux:button>
    </form>
</div>
