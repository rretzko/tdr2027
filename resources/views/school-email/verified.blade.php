<x-layouts.auth>
    <div class="flex flex-col gap-2 text-center">
        <flux:heading size="lg">School email verified</flux:heading>
        <flux:text class="text-zinc-500">
            Your school email has been verified for {{ $school->name }}. You can close this page.
        </flux:text>

        @if ($transferredStudentCount > 0)
            <flux:text class="text-zinc-500">
                {{ $transferredStudentCount }} {{ \Illuminate\Support\Str::plural('student', $transferredStudentCount) }} {{ $transferredStudentCount === 1 ? 'has' : 'have' }} been transferred to you.
            </flux:text>
        @endif
    </div>
</x-layouts.auth>
