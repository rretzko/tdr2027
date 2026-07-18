<div>
    <div class="mb-6">
        <a href="{{ route('registrations.index') }}" wire:navigate class="text-sm text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
            &larr; Registrations
        </a>
        <flux:heading size="xl" class="mt-1">{{ $version->name }}</flux:heading>
        <flux:text size="sm" class="text-zinc-500">{{ $version->event->name }}</flux:text>
    </div>

    <flux:callout icon="information-circle">
        <flux:callout.text>Results reporting is coming soon.</flux:callout.text>
    </flux:callout>
</div>
