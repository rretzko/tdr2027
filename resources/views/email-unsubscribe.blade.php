<x-layouts.auth>
    <div class="flex flex-col gap-4 text-center">
        @if ($unsubscribed)
            <flux:heading size="lg">You're unsubscribed</flux:heading>
            <flux:text class="text-zinc-500">
                You won't receive the {{ $email->label() }} email for any event. You can turn it back on any time
                under Settings → Email Preferences after logging in.
            </flux:text>
        @else
            <flux:heading size="lg">Unsubscribe from the {{ $email->label() }}?</flux:heading>
            <flux:text class="text-zinc-500">
                {{ $email->description() }} Unsubscribing stops it for every event.
            </flux:text>

            <form method="POST" action="{{ $action }}">
                @csrf
                <flux:button type="submit" variant="danger" class="w-full">Unsubscribe</flux:button>
            </form>
        @endif
    </div>
</x-layouts.auth>
