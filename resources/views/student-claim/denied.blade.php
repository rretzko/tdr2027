<x-layouts.auth>
    <div class="flex flex-col gap-2 text-center">
        <flux:heading size="lg">Request denied</flux:heading>
        <flux:text class="text-zinc-500">
            The request to add {{ $student->user->name }} at {{ $school->name }} has been denied. You can close this page.
        </flux:text>
    </div>
</x-layouts.auth>
