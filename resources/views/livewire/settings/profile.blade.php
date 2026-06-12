<x-settings.layout>
    <div class="flex flex-col gap-6">
        <flux:heading size="lg">Profile</flux:heading>

        <form wire:submit="update" class="flex flex-col gap-6">
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
                <flux:input wire:model="first_name" label="First name" required />
                <flux:input wire:model="middle_name" label="Middle name (optional)" />
                <flux:input wire:model="last_name" label="Last name" required />
            </div>

            <flux:input wire:model="suffix_name" label="Suffix (optional)" placeholder="Jr., Sr., III, etc." />

            <flux:input wire:model="email" label="Email address" type="email" required autocomplete="email" />

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
