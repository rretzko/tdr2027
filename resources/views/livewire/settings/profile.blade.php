<x-settings.layout>
    <div class="flex flex-col gap-6">
        <flux:heading size="lg">Profile</flux:heading>

        @php $isStudent = auth()->user()->student !== null; @endphp

        <div class="flex items-center gap-4">
            @php $avatar = auth()->user()->avatarUrl(); @endphp
            @if ($avatar)
                <img src="{{ $avatar }}" alt="" class="h-16 w-16 rounded-full object-cover">
            @else
                <flux:icon.user-circle class="h-16 w-16 text-zinc-400" />
            @endif

            <div class="flex flex-col gap-2">
                <input
                    type="file"
                    wire:model="photo"
                    accept="image/*"
                    class="block text-sm text-zinc-600 file:mr-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-300"
                />
                <div wire:loading wire:target="photo">
                    <flux:text size="sm" class="text-zinc-400">Uploading…</flux:text>
                </div>
                <flux:error name="photo" />

                @if (auth()->user()->photo_path !== null)
                    <flux:button size="sm" variant="ghost" wire:click="removePhoto" wire:confirm="Remove your profile photo?">
                        Remove photo
                    </flux:button>
                @endif
            </div>
        </div>

        <form wire:submit="update" class="flex flex-col gap-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @unless ($isStudent)
                    <flux:select wire:model="honorific" label="Honorific (optional)" placeholder="Select...">
                        <flux:select.option value="Mr.">Mr.</flux:select.option>
                        <flux:select.option value="Mrs.">Mrs.</flux:select.option>
                        <flux:select.option value="Ms.">Ms.</flux:select.option>
                        <flux:select.option value="Mx.">Mx.</flux:select.option>
                        <flux:select.option value="Dr.">Dr.</flux:select.option>
                        <flux:select.option value="Prof.">Prof.</flux:select.option>
                        <flux:select.option value="Rev.">Rev.</flux:select.option>
                    </flux:select>
                @endunless

                <flux:select wire:model="pronoun_id" label="Pronouns" class="{{ $isStudent ? 'sm:col-span-2' : '' }}">
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

            <div wire:dirty.class.remove="hidden" class="hidden" wire:target="email">
                <flux:callout color="yellow" icon="envelope">
                    <flux:callout.text>
                        Changing your email address requires re-verification. A link will be sent to your new address when you save.
                    </flux:callout.text>
                </flux:callout>
            </div>

            <flux:input
                wire:model="cell_phone"
                label="Cell phone"
                type="tel"
                required
                autocomplete="tel"
                mask:dynamic="$input.replace(/\D/g, '').length > 10 ? '(999) 999-9999 x9999' : '(999) 999-9999'"
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
