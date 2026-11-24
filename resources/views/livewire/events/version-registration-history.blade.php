<div class="space-y-6">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <flux:field>
            <flux:label>Teacher</flux:label>
            <flux:input wire:model.live.debounce.300ms="teacherSearch" placeholder="Search teachers by name..." icon="magnifying-glass" />
        </flux:field>

        <flux:field>
            <flux:label>Student</flux:label>
            <flux:input wire:model.live.debounce.300ms="studentSearch" placeholder="Search students by name..." icon="magnifying-glass" />
        </flux:field>
    </div>

    @if ($teachers !== null)
        <div class="flex flex-col gap-2">
            @forelse ($teachers as $teacher)
                <div wire:key="teacher-{{ $teacher->id }}" class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <div>
                        <flux:text class="font-medium">{{ $teacher->user->name }}</flux:text>
                        <flux:text size="sm" class="text-zinc-500">{{ $teacher->schools->pluck('name')->join(', ') ?: 'No active school' }}</flux:text>
                    </div>
                    <flux:button size="sm" wire:click="selectTeacher({{ $teacher->id }})">View history</flux:button>
                </div>
            @empty
                <flux:text size="sm" class="text-zinc-400">No teacher matches that name.</flux:text>
            @endforelse

            @if ($teachers->count() === $maxResults)
                <flux:text size="sm" class="text-zinc-500">Showing {{ $maxResults }} matches. Add more of the name to narrow the list.</flux:text>
            @endif
        </div>
    @endif

    @if ($candidates !== null)
        <div class="flex flex-col gap-2">
            @forelse ($candidates as $candidate)
                <div wire:key="candidate-{{ $candidate->id }}" class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <div>
                        <flux:text class="font-medium">{{ $candidate->student->user->name }} ({{ $candidate->ref }})</flux:text>
                        <flux:text size="sm" class="text-zinc-500">{{ $candidate->school?->name ?? 'No school' }} · {{ $candidate->teacher?->user?->name ?? 'No teacher' }} · {{ $statuses[$candidate->id] }}</flux:text>
                    </div>
                    <flux:button size="sm" wire:click="selectStudent({{ $candidate->id }})">View history</flux:button>
                </div>
            @empty
                <flux:text size="sm" class="text-zinc-400">No student matches that name.</flux:text>
            @endforelse

            @if ($candidates->count() === $maxResults)
                <flux:text size="sm" class="text-zinc-500">Showing {{ $maxResults }} matches. Add more of the name to narrow the list.</flux:text>
            @endif
        </div>
    @endif

    @if ($history !== null)
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="lg">{{ $history->kind }}: {{ $history->name }}</flux:heading>
                @foreach ($history->facts as $label => $value)
                    <flux:text size="sm"><span class="font-medium">{{ $label }}:</span> {{ $value }}</flux:text>
                @endforeach
            </div>
            <div class="flex gap-2">
                <flux:button size="sm" variant="ghost" wire:click="clearSelection">Back to search</flux:button>
                <flux:button size="sm" icon="arrow-down-tray" wire:click="download">Download .txt</flux:button>
            </div>
        </div>

        @if ($history->checklist !== [])
            <flux:card size="sm">
                <flux:heading class="mb-2">Registration Checklist</flux:heading>
                <div class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                    @foreach ($history->checklist as $item)
                        <div class="flex items-center gap-2">
                            @if ($item['done'])
                                <flux:icon.check-circle variant="micro" class="text-emerald-600 dark:text-emerald-400" />
                            @else
                                <flux:icon.x-circle variant="micro" class="text-red-600 dark:text-red-400" />
                            @endif
                            <flux:text size="sm">{{ $item['label'] }}{{ $item['done'] ? '' : ' (not done)' }}</flux:text>
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @endif

        @if ($history->incomplete !== [])
            <flux:card size="sm">
                <flux:heading class="mb-2">Incomplete students</flux:heading>
                @foreach ($history->incomplete as $row)
                    <flux:text size="sm"><span class="font-medium">{{ $row['student'] }}</span> ({{ $row['status'] }}): {{ implode('; ', $row['missing']) ?: 'checklist complete' }}</flux:text>
                @endforeach
            </flux:card>
        @endif

        {{-- Cards below md --}}
        <div class="space-y-2 md:hidden">
            @foreach ($history->entries as $entry)
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 {{ $entry->isDeadline ? 'bg-amber-50 dark:bg-amber-950/30' : 'bg-white dark:bg-zinc-900' }}">
                    <flux:text size="sm" class="text-zinc-500">{{ $entry->displayAt() }}</flux:text>
                    @if ($entry->student !== null)
                        <flux:text size="sm" class="font-medium">{{ $entry->student }}</flux:text>
                    @endif
                    <flux:text class="break-words">{{ $entry->description }}</flux:text>
                    @if ($entry->actor !== null)
                        <flux:text size="sm" class="text-zinc-500">By {{ $entry->actor }}</flux:text>
                    @endif
                </div>
            @endforeach
        </div>

        <flux:table class="hidden md:table">
            <flux:table.columns>
                <flux:table.column>When (Eastern)</flux:table.column>
                @if ($history->isTeacher())
                    <flux:table.column>Student</flux:table.column>
                @endif
                <flux:table.column>What happened</flux:table.column>
                <flux:table.column>By</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($history->entries as $entry)
                    <flux:table.row class="{{ $entry->isDeadline ? 'bg-amber-50 dark:bg-amber-950/30' : 'bg-white dark:bg-zinc-900' }}">
                        <flux:table.cell class="whitespace-nowrap">{{ $entry->displayAt() }}</flux:table.cell>
                        @if ($history->isTeacher())
                            <flux:table.cell class="whitespace-normal">{{ $entry->student ?? '—' }}</flux:table.cell>
                        @endif
                        <flux:table.cell class="whitespace-normal break-words">{{ $entry->description }}</flux:table.cell>
                        <flux:table.cell>{{ $entry->actor ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <flux:callout variant="secondary" icon="information-circle">
            <flux:callout.heading>About these records</flux:callout.heading>
            <flux:callout.text>
                <ul class="list-disc space-y-1 ps-4">
                    @foreach ($notes as $note)
                        <li>{{ $note }}</li>
                    @endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif
</div>
