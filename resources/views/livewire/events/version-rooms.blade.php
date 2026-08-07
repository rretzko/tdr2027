<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.edit', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Rooms</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">Rooms</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — adjudication rooms, scoring rubric, and voice parts</flux:text>
        </div>

        <div class="flex gap-2">
            @if ($rooms->isNotEmpty())
                <flux:button size="sm" variant="outline" icon="printer" href="{{ route('events.versions.rooms.roster-pdf', $version) }}" target="_blank">
                    Print roster
                </flux:button>
            @endif

            <flux:button
                size="sm"
                variant="primary"
                icon="plus"
                type="button"
                x-on:click="$wire.add().then(() => $dispatch('modal-show', { name: 'room-form' }))"
            >
                Add room
            </flux:button>
        </div>
    </div>

    @if ($rooms->isEmpty())
        <flux:callout variant="info" icon="magnifying-glass">
            <flux:callout.text>No rooms have been added to this Version yet.</flux:callout.text>
        </flux:callout>
    @else
        {{-- Cards below lg:, table at lg:+ --}}
        <div class="lg:hidden space-y-3">
            @foreach ($rooms as $room)
                <flux:card size="sm">
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <flux:heading size="base" class="truncate">{{ $room->name }}</flux:heading>
                        <div class="flex flex-wrap justify-end gap-1">
                            <flux:badge color="zinc" size="sm">Tolerance: {{ $room->tolerance ?? '—' }}</flux:badge>
                            <flux:badge color="green" size="sm">Candidates: {{ $roomCandidateCounts[$room->id] ?? 0 }}</flux:badge>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-1 mb-2">
                        @forelse ($room->scoreCategories as $category)
                            <flux:badge color="blue" size="sm">{{ $category->description }}</flux:badge>
                        @empty
                            <flux:text size="sm" class="text-zinc-400">No score categories assigned</flux:text>
                        @endforelse
                    </div>

                    <div class="flex flex-wrap gap-1 mb-2">
                        @forelse ($room->voiceParts as $voicePart)
                            <flux:badge color="zinc" size="sm">{{ $voicePart->name }}</flux:badge>
                        @empty
                            <flux:text size="sm" class="text-zinc-400">No voice parts assigned</flux:text>
                        @endforelse
                    </div>

                    <div class="flex flex-wrap gap-1 mb-2">
                        @forelse ($room->roomJudges as $roomJudge)
                            <flux:badge color="amber" size="sm">{{ $roomJudge->judge_type->label() }}: {{ $roomJudge->user->name }}</flux:badge>
                        @empty
                            <flux:text size="sm" class="text-zinc-400">No judges assigned</flux:text>
                        @endforelse
                    </div>

                    <div class="flex items-center gap-2 mt-2">
                        <flux:input type="number" min="1" max="255" wire:model="orderInputs.{{ $room->id }}" size="sm" class="w-20" />

                        <flux:spacer />

                        <flux:button
                            size="sm"
                            variant="outline"
                            type="button"
                            x-on:click="$wire.edit({{ $room->id }}).then(() => $dispatch('modal-show', { name: 'room-form' }))"
                        >
                            Edit
                        </flux:button>

                        <flux:button size="sm" variant="danger" wire:click="remove({{ $room->id }})" wire:confirm="Remove &quot;{{ $room->name }}&quot;? This cannot be undone.">
                            Remove
                        </flux:button>
                    </div>
                </flux:card>
            @endforeach

            <flux:button size="sm" wire:click="saveOrder">Save Order</flux:button>
        </div>

        <div class="hidden lg:block">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column></flux:table.column>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column align="center">Tolerance</flux:table.column>
                    <flux:table.column align="center">Score Categories</flux:table.column>
                    <flux:table.column>Voice Parts</flux:table.column>
                    <flux:table.column>Judges</flux:table.column>
                    <flux:table.column align="center">Candidates</flux:table.column>
                    <flux:table.column align="center">Order</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($rooms as $room)
                        <flux:table.row
                            :key="$room->id"
                            draggable="true"
                            @dragstart="$event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', '{{ $room->id }}'); setTimeout(() => $el.classList.add('opacity-40'))"
                            @dragend="$el.classList.remove('opacity-40')"
                            @dragover.prevent="$event.dataTransfer.dropEffect = 'move'; $el.classList.add('bg-zinc-50', 'dark:bg-zinc-800')"
                            @dragleave="$el.classList.remove('bg-zinc-50', 'dark:bg-zinc-800')"
                            @drop.prevent="
                                $el.classList.remove('bg-zinc-50', 'dark:bg-zinc-800');
                                $wire.reorderRooms(parseInt($event.dataTransfer.getData('text/plain')), {{ $loop->index }});
                            "
                            class="transition-[background-color,opacity] cursor-grab active:cursor-grabbing"
                        >
                            <flux:table.cell>
                                <flux:icon.bars-3 variant="micro" class="cursor-grab text-zinc-400" />
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="font-medium">{{ $room->name }}</div>
                            </flux:table.cell>
                            <flux:table.cell align="center">{{ $room->tolerance ?? '—' }}</flux:table.cell>
                            <flux:table.cell align="center">
                                <div class="flex flex-wrap justify-center gap-1 max-w-[220px] mx-auto">
                                    @forelse ($room->scoreCategories as $category)
                                        <flux:badge color="blue" size="sm">{{ $category->description }}</flux:badge>
                                    @empty
                                        <span class="text-zinc-400">—</span>
                                    @endforelse
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex flex-wrap gap-1 max-w-[220px]">
                                    @forelse ($room->voiceParts as $voicePart)
                                        <flux:badge color="zinc" size="sm">{{ $voicePart->name }}</flux:badge>
                                    @empty
                                        <span class="text-zinc-400">—</span>
                                    @endforelse
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex flex-wrap gap-1 max-w-[220px]">
                                    @forelse ($room->roomJudges as $roomJudge)
                                        <flux:badge color="amber" size="sm">{{ $roomJudge->judge_type->label() }}: {{ $roomJudge->user->name }}</flux:badge>
                                    @empty
                                        <span class="text-zinc-400">—</span>
                                    @endforelse
                                </div>
                            </flux:table.cell>
                            <flux:table.cell align="center">{{ $roomCandidateCounts[$room->id] ?? 0 }}</flux:table.cell>
                            <flux:table.cell align="center">{{ $room->order_by }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    <flux:button
                                        size="sm"
                                        variant="outline"
                                        type="button"
                                        x-on:click="$wire.edit({{ $room->id }}).then(() => $dispatch('modal-show', { name: 'room-form' }))"
                                    >
                                        Edit
                                    </flux:button>

                                    <flux:button size="sm" variant="danger" wire:click="remove({{ $room->id }})" wire:confirm="Remove &quot;{{ $room->name }}&quot;? This cannot be undone.">
                                        Remove
                                    </flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <flux:text size="sm" class="text-zinc-400 mt-2">Drag a row and drop it on another to reorder, or use Save Order in the mobile view.</flux:text>
        </div>
    @endif

    <flux:modal name="room-form" class="md:w-[32rem]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId === null ? 'Add room' : 'Edit room' }}</flux:heading>
                <flux:subheading>{{ $version->name }}</flux:subheading>
            </div>

            <flux:input wire:model="name" label="Room Name" placeholder="ex. Soprano I" autofocus />

            <flux:input
                type="number"
                min="0"
                max="255"
                wire:model="tolerance"
                label="Tolerance"
                description="Acceptable point spread between judge totals. Leave blank if not applied; zero requires identical scores."
            />

            <div>
                <flux:label>Score Categories</flux:label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-48 overflow-y-auto pr-1 mt-1">
                    @forelse ($availableScoreCategories as $category)
                        <flux:checkbox wire:model="scoreCategoryIds" value="{{ $category->id }}" label="{{ $category->description }}" />
                    @empty
                        <flux:text size="sm" class="text-zinc-400">No score categories configured for this Event/Version yet.</flux:text>
                    @endforelse
                </div>
                <flux:error name="scoreCategoryIds" />
            </div>

            <div>
                <flux:label>Voice Parts</flux:label>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 max-h-48 overflow-y-auto pr-1 mt-1">
                    @foreach ($availableVoiceParts as $voicePart)
                        <flux:checkbox wire:model="voicePartIds" value="{{ $voicePart->id }}" label="{{ $voicePart->name }}" />
                    @endforeach
                </div>
                <flux:error name="voicePartIds" />
            </div>

            <div>
                <div class="flex items-center justify-between mb-1">
                    <flux:label>Judges</flux:label>
                    <flux:button
                        size="xs"
                        variant="ghost"
                        icon="plus"
                        type="button"
                        x-on:click="$wire.addJudgeRow().then(() => $nextTick(() => {
                            let rows = document.querySelectorAll('[data-judge-search]');
                            rows[rows.length - 1]?.focus();
                        }))"
                    >
                        Add judge
                    </flux:button>
                </div>

                <div class="space-y-3">
                    @forelse ($judges as $index => $judge)
                        <div>
                            <div class="flex items-start gap-2">
                                <div class="flex-1 relative">
                                    <flux:input
                                        wire:model.live.debounce.300ms="judges.{{ $index }}.search"
                                        wire:keydown.enter.prevent
                                        placeholder="Search judge by name..."
                                        size="sm"
                                        autocomplete="off"
                                        :disabled="($judge['user_id'] ?? null) !== null"
                                        data-judge-search
                                    />
                                    <flux:error name="judges.{{ $index }}.user_id" />

                                    @if (($judge['user_id'] ?? null) === null && trim($judge['search'] ?? '') !== '')
                                        @php $results = $this->judgeSearchResults($index); @endphp
                                        <div class="absolute z-10 mt-1 w-full rounded-md border border-zinc-200 bg-white shadow-md dark:border-zinc-700 dark:bg-zinc-800 max-h-40 overflow-y-auto">
                                            @forelse ($results as $result)
                                                <button
                                                    type="button"
                                                    wire:click="selectJudgeUser({{ $index }}, {{ $result->id }})"
                                                    class="block w-full text-left px-3 py-1.5 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-700"
                                                >
                                                    {{ $result->name }}
                                                </button>
                                            @empty
                                                <div class="px-3 py-1.5 text-sm text-zinc-400">No users match.</div>
                                            @endforelse
                                        </div>
                                    @endif
                                </div>
                                <div class="flex-1">
                                    <flux:select wire:model="judges.{{ $index }}.judge_type" placeholder="Role..." size="sm">
                                        @foreach ($judgeTypeOptions as $option)
                                            <flux:select.option value="{{ $option->value }}">{{ $option->label() }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:error name="judges.{{ $index }}.judge_type" />
                                </div>
                                @if (($judge['user_id'] ?? null) !== null)
                                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearJudgeSelection({{ $index }})" type="button" />
                                @endif
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeJudgeRow({{ $index }})" type="button" />
                            </div>

                            @if (($judge['user_id'] ?? null) !== null)
                                @php $history = $this->judgeAssignmentHistory($index); @endphp
                                <div class="mt-1.5 ms-1 rounded-md border border-zinc-200 bg-zinc-50 px-2.5 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800/50">
                                    <div class="font-medium text-zinc-500 dark:text-zinc-400 mb-1">Assignment history — {{ $version->event->name }}</div>
                                    @if ($history->isEmpty())
                                        <flux:text size="xs" class="text-zinc-400">No prior Room assignments for this judge.</flux:text>
                                    @else
                                        <ul class="space-y-0.5">
                                            @foreach ($history as $roomJudge)
                                                <li class="text-zinc-600 dark:text-zinc-300">
                                                    {{ $roomJudge->version->name }} — {{ $roomJudge->room->name }} ({{ $roomJudge->judge_type->label() }})
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @empty
                        <flux:text size="sm" class="text-zinc-400">No judges assigned to this room yet.</flux:text>
                    @endforelse
                </div>
            </div>

            @if ($errors->any())
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>Please correct the errors above before saving.</flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">
                    {{ $editingId === null ? 'Add' : 'Save' }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
