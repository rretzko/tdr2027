<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2 text-center">
        <flux:heading size="xl">Confirm password</flux:heading>
        <flux:subheading>Please confirm your password before continuing</flux:subheading>
    </div>

    <form wire:submit="confirm" class="flex flex-col gap-6">
        <flux:input
            wire:model="password"
            label="Password"
            type="password"
            required
            autofocus
            autocomplete="current-password"
            viewable
        />

        <flux:button type="submit" variant="primary">
            Confirm
        </flux:button>
    </form>
</div>
