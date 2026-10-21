<x-settings.layout>
    <div class="flex flex-col gap-6">
        <div>
            <flux:heading size="lg">Email Preferences</flux:heading>
            <flux:text class="mt-1">
                Scheduled summary emails. Each one stops automatically when an event's adjudication begins.
                Account emails (verification links, approvals, payment notices) are always sent.
            </flux:text>
        </div>

        @foreach (\App\Enums\UnsubscribableEmail::cases() as $email)
            <flux:switch
                wire:model.live="subscribed.{{ $email->value }}"
                :label="$email->label()"
                :description="$email->description()"
            />
        @endforeach
    </div>
</x-settings.layout>
