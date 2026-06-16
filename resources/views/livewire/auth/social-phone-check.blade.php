<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2 text-center">
        <flux:heading size="xl">One more step</flux:heading>
        <flux:subheading>
            Enter your cell phone number so we can link your account or create a new one.
        </flux:subheading>
    </div>

    <flux:callout color="blue" icon="information-circle" heading="Why your cell phone?">
        <flux:callout.text>
            Your cell phone is your unique identifier on TheDirectorsRoom.com. If you already
            have an account, entering your phone will connect it to this login method.
        </flux:callout.text>
    </flux:callout>

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:input
            wire:model="cell_phone"
            label="Cell phone"
            type="tel"
            required
            autofocus
            autocomplete="tel"
            mask:dynamic="$input.replace(/\D/g, '').length > 10 ? '(999) 999-9999 x9999' : '(999) 999-9999'"
        />

        <flux:button type="submit" variant="primary">Continue</flux:button>
    </form>
</div>
