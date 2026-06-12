<x-settings.layout>
    <div class="flex flex-col gap-6">
        <flux:heading size="lg">Password</flux:heading>

        <form wire:submit="update" class="flex flex-col gap-6">
            <flux:input
                wire:model="current_password"
                label="Current password"
                type="password"
                required
                autocomplete="current-password"
                viewable
            />

            <flux:input
                wire:model="password"
                label="New password"
                type="password"
                required
                autocomplete="new-password"
                viewable
            />

            <flux:input
                wire:model="password_confirmation"
                label="Confirm new password"
                type="password"
                required
                autocomplete="new-password"
                viewable
            />

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">
                    Save
                </flux:button>

                @if ($saved)
                    <flux:text class="text-green-600 dark:text-green-400">Saved.</flux:text>
                @endif
            </div>
        </form>
    </div>
</x-settings.layout>
