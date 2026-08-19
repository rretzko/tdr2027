<div class="flex flex-col gap-6">
    <flux:heading size="xl">Student Details</flux:heading>

    @if ($grade !== null)
        <flux:callout icon="academic-cap">
            <flux:callout.text>You're in grade {{ $grade }}.</flux:callout.text>
        </flux:callout>
    @endif

    <form wire:submit="update" class="flex flex-col gap-6">
        <flux:card class="flex flex-col gap-4">
            <flux:subheading>About you</flux:subheading>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select wire:model="voice_part_id" label="Preferred voice part" placeholder="Select...">
                    @foreach ($voiceParts as $voicePart)
                        <flux:select.option value="{{ $voicePart->id }}">{{ $voicePart->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="shirt_size" label="Shirt size" placeholder="Select...">
                    @foreach ($shirtSizeOptions as $size)
                        <flux:select.option value="{{ $size->value }}">{{ $size->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input wire:model="birthday" label="Birthday" type="date" />
                <flux:select wire:model="height" label="Height (in)" placeholder="Select height...">
                    @for ($inches = 30; $inches <= 84; $inches++)
                        <flux:select.option value="{{ $inches }}">{{ $inches }} ({{ intdiv($inches, 12) }}'{{ $inches % 12 }}")</flux:select.option>
                    @endfor
                </flux:select>
            </div>
            <flux:error name="voice_part_id" />
            <flux:error name="birthday" />
            <flux:error name="shirt_size" />
            <flux:error name="height" />
        </flux:card>

        <flux:card class="flex flex-col gap-4">
            <flux:subheading>Home address</flux:subheading>

            <flux:input wire:model="home_address1" label="Address 1" />
            <flux:input wire:model="home_address2" label="Address 2 (optional)" />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <flux:input wire:model="home_city" label="City" class="sm:col-span-1" />
                <flux:input wire:model="home_geo_state" label="State" maxlength="2" />
                <flux:input wire:model="home_zip_code" label="Zip code" />
            </div>
            <flux:error name="home_address1" />
            <flux:error name="home_city" />
            <flux:error name="home_geo_state" />
            <flux:error name="home_zip_code" />
        </flux:card>

        <div class="flex items-center gap-4">
            <flux:button type="submit" variant="primary">Save</flux:button>

            @if ($saved)
                <flux:text class="text-green-600 dark:text-green-400">Saved.</flux:text>
            @endif
        </div>
    </form>
</div>
