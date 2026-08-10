<div wire:poll.10s>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.tab-room.index', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Tab Room</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Adjudication Tracking</span>
    </div>

    <div class="mb-6">
        <flux:heading size="xl">Adjudication Tracking</flux:heading>
        <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — {{ array_sum($overallCounts) }} candidates</flux:text>
    </div>

    {{-- Overall progress indicator --}}
    <flux:card class="mb-4">
        <div class="flex h-6 w-full overflow-hidden rounded-md text-xs font-medium text-white">
            <div class="bg-green-500 flex items-center justify-center" style="width: {{ $overallPercentages['completed'] }}%">{{ $overallCounts['completed'] ?: '' }}</div>
            <div class="bg-yellow-500 flex items-center justify-center" style="width: {{ $overallPercentages['partial'] }}%">{{ $overallCounts['partial'] ?: '' }}</div>
            <div class="bg-red-500 flex items-center justify-center" style="width: {{ $overallPercentages['none'] }}%">{{ $overallCounts['none'] ?: '' }}</div>
            <div class="bg-zinc-900 flex items-center justify-center" style="width: {{ $overallPercentages['error'] }}%">{{ $overallCounts['error'] ?: '' }}</div>
        </div>
        <div class="flex flex-wrap gap-3 mt-2 text-xs text-zinc-500">
            <span><span class="inline-block w-2.5 h-2.5 rounded-sm bg-green-500 me-1"></span>Completed ({{ $overallCounts['completed'] }})</span>
            <span><span class="inline-block w-2.5 h-2.5 rounded-sm bg-yellow-500 me-1"></span>Pending ({{ $overallCounts['partial'] }})</span>
            <span><span class="inline-block w-2.5 h-2.5 rounded-sm bg-red-500 me-1"></span>Not started ({{ $overallCounts['none'] }})</span>
            <span><span class="inline-block w-2.5 h-2.5 rounded-sm bg-zinc-900 me-1"></span>Error ({{ $overallCounts['error'] }})</span>
        </div>
    </flux:card>

    {{-- Room selection --}}
    <flux:card class="mb-4">
        <flux:heading size="lg" class="mb-2">Rooms</flux:heading>
        <div class="flex flex-wrap gap-4">
            @forelse ($rooms as $room)
                <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                    <input type="radio" wire:click="selectRoom({{ $room->id }})" @checked($selectedRoom?->id === $room->id) name="room" class="text-zinc-800 focus:ring-zinc-800 dark:text-zinc-200">
                    <span
                        @class([
                            'inline-block w-2.5 h-2.5 rounded-full',
                            'bg-green-500' => ($roomRag[$room->id] ?? 'red') === 'green',
                            'bg-amber-500' => ($roomRag[$room->id] ?? 'red') === 'amber',
                            'bg-red-500' => ($roomRag[$room->id] ?? 'red') === 'red',
                        ])
                        title="{{ ucfirst($roomRag[$room->id] ?? 'red') }}"
                    ></span>
                    {{ $room->name }}
                    @if ($roomOutOfTolerance[$room->id] ?? false)
                        <span class="text-red-500 font-bold" title="Contains an out-of-tolerance candidate">*</span>
                    @endif
                </label>
            @empty
                <flux:text size="sm" class="text-zinc-500">No Rooms configured yet.</flux:text>
            @endforelse
        </div>
    </flux:card>

    @if ($selectedRoom)
        {{-- Room Overview --}}
        <flux:card class="mb-4">
            <div class="flex items-start justify-between gap-3 mb-2">
                <div>
                    <flux:heading size="lg">{{ $selectedRoom->name }}</flux:heading>
                    <flux:text size="sm" class="text-zinc-500">Tolerance: {{ $selectedRoom->tolerance ?? '—' }} &bull; {{ $candidates->count() }} candidates</flux:text>
                </div>
            </div>

            <div class="space-y-2">
                @foreach ($judgeProgress as $row)
                    <div class="flex items-center gap-2 text-sm">
                        <span class="w-40 shrink-0 truncate">{{ $row['judge']->judge_type->label() }} — {{ $row['judge']->user->name }}</span>
                        <div class="flex-1 h-3 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                            <div class="h-full bg-green-500" style="width: {{ $row['percent'] }}%"></div>
                        </div>
                        <span class="w-16 text-right text-zinc-500 text-xs">{{ $row['scoredCount'] }}/{{ $candidates->count() }}</span>
                    </div>
                @endforeach
            </div>
        </flux:card>

        {{-- Candidate Overview --}}
        <flux:card>
            <flux:heading size="lg" class="mb-3">Candidates</flux:heading>

            @if ($candidates->isEmpty())
                <flux:text size="sm" class="text-zinc-500">No registered candidates in this room's voice part(s) yet.</flux:text>
            @else
                <div class="flex flex-wrap gap-1.5" x-data="{ open: null }">
                    @foreach ($candidates as $candidate)
                        <div class="relative" @mouseenter="open = {{ $candidate->id }}" @mouseleave="open = null">
                            <span
                                @class([
                                    'inline-flex w-20 items-center justify-center gap-1 rounded-md px-2 py-1.5 text-sm font-medium tabular-nums cursor-default',
                                    'bg-green-500 text-white' => $statuses[$candidate->id] === 'completed',
                                    'bg-yellow-500 text-black' => $statuses[$candidate->id] === 'partial',
                                    'bg-red-500 text-white' => $statuses[$candidate->id] === 'none',
                                    'bg-zinc-900 text-white' => $statuses[$candidate->id] === 'error',
                                ])
                            >
                                <span @class(['w-3 shrink-0 text-center font-bold text-red-500', 'invisible' => $tolerances[$candidate->id] ?? true]) title="Out of tolerance">*</span>
                                <span @class(['text-red-500 font-bold' => ! ($tolerances[$candidate->id] ?? true)])>{{ $candidate->id }}</span>
                            </span>

                            <div
                                x-show="open === {{ $candidate->id }}"
                                x-cloak
                                x-transition
                                class="absolute z-10 mt-1 w-72 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-lg p-3 text-xs space-y-1"
                            >
                                <div class="font-semibold">{{ $candidate->student->user->name }}</div>
                                <div class="text-zinc-500">{{ $candidate->teacher?->user?->name ?? '—' }} @ {{ $candidate->school?->name ?? '—' }}</div>
                                <div class="border-t border-zinc-200 dark:border-zinc-700 my-1 pt-1 space-y-0.5">
                                    @foreach (($breakdown[$candidate->id] ?? collect()) as $row)
                                        <div class="flex justify-between gap-2">
                                            <span>{{ $row['judge']->judge_type->label() }} {{ $row['judge']->user->name }}</span>
                                            <span class="tabular-nums">{{ $row['enteredCount'] }}/{{ $row['factorCount'] }} ({{ $row['total'] }})</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-wrap gap-3 mt-3 pt-2 border-t border-zinc-200 dark:border-zinc-700 text-xs text-zinc-500">
                    <span><span class="text-red-500 font-bold">*</span> Out of tolerance</span>
                </div>
            @endif
        </flux:card>
    @endif
</div>
