@props(['student', 'clearMethod', 'color' => 'zinc'])

@php
    $borderClass = match($color) {
        'green' => 'border-green-500',
        'red'   => 'border-red-500',
        default => 'border-zinc-300 dark:border-zinc-600',
    };
@endphp

<div class="rounded-lg border-2 {{ $borderClass }} bg-white dark:bg-zinc-800 p-4 space-y-3">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <flux:text class="text-lg font-semibold">
                {{ $student->user->first_name }} {{ $student->user->last_name }}
            </flux:text>
            <flux:text size="sm" class="truncate text-zinc-500">{{ $student->user->email }}</flux:text>
        </div>
        <flux:button size="sm" variant="ghost" wire:click="{{ $clearMethod }}">Change</flux:button>
    </div>

    <div class="divide-y divide-zinc-100 dark:divide-zinc-700 text-sm">
        <div class="flex justify-between py-1.5">
            <flux:text class="text-zinc-500">Student ID</flux:text>
            <flux:text class="font-mono">{{ $student->id }}</flux:text>
        </div>

        <div class="flex justify-between py-1.5">
            <flux:text class="text-zinc-500">Birthday</flux:text>
            <flux:text>{{ $student->birthday?->format('M j, Y') ?? '—' }}</flux:text>
        </div>

        <div class="flex justify-between py-1.5">
            <flux:text class="text-zinc-500">Schools</flux:text>
            <div class="text-right">
                @forelse ($student->schools as $school)
                    <flux:text>{{ $school->name }} (Class of {{ $school->pivot->class_of }})</flux:text>
                @empty
                    <flux:text class="text-zinc-400">None</flux:text>
                @endforelse
            </div>
        </div>

        <div class="flex justify-between py-1.5">
            <flux:text class="text-zinc-500">Emergency contacts</flux:text>
            <flux:text>{{ $student->emergencyContacts->count() }}</flux:text>
        </div>

        <div class="flex justify-between py-1.5">
            <flux:text class="text-zinc-500">Home address</flux:text>
            @if ($student->homeAddress)
                <flux:badge color="green" size="sm">On file</flux:badge>
            @else
                <flux:badge color="zinc" size="sm">None</flux:badge>
            @endif
        </div>
    </div>
</div>
