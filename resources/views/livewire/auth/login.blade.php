<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2 text-center">
        <flux:heading size="xl">Log in to your account</flux:heading>
        <flux:subheading>Enter your email and password below to log in</flux:subheading>
    </div>

    <form wire:submit="login" class="flex flex-col gap-6">
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
            autocomplete="current-password"
            viewable
        />

        <flux:checkbox wire:model="remember" label="Remember me" />

        <div class="flex items-center justify-between">
            <flux:link :href="route('password.request')" wire:navigate>
                Forgot your password?
            </flux:link>

            <flux:button type="submit" variant="primary">
                Log in
            </flux:button>
        </div>
    </form>

    <flux:separator text="or" />

    <div class="flex flex-col gap-2 text-center text-sm">
        <flux:link href="/tdr/register" wire:navigate>
            Register as a Teacher / Event Manager
        </flux:link>
        <flux:link href="/sfdi/register" wire:navigate>
            Register as a Student
        </flux:link>
    </div>
</div>
