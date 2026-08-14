<div>
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('registrations.index') }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Registrations</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('registrations.version', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Estimate Form</span>
    </div>

    <flux:heading size="xl" class="mb-6">Estimate Form</flux:heading>

    <flux:callout variant="info" icon="information-circle">
        <flux:callout.text>
            This form isn't built yet. Every Event requires an Estimate form submitted to the Version's
            Registration Manager — check back soon.
        </flux:callout.text>
    </flux:callout>
</div>
