<x-layouts.auth>
    <div class="flex flex-col gap-2 text-center">
        <flux:heading size="lg">Request approved</flux:heading>
        <flux:text class="text-zinc-500">
            {{ $student->user->name }} has been added as a student at {{ $school->name }}. You can close this page.
        </flux:text>
    </div>
</x-layouts.auth>
