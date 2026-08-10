<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.tab-room.index', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Tab Room</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Add/Edit Scores</span>
    </div>

    <div class="mb-6">
        <flux:heading size="xl">Add/Edit Scores</flux:heading>
        <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — review and update judge scores for a candidate</flux:text>
    </div>

    {{-- Filter by candidate id or name --}}
    <flux:card class="mb-4">
        <flux:heading size="lg" class="mb-2">Filter by candidate id or name</flux:heading>
        <form wire:submit="search" class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-end">
            <flux:field>
                <flux:label>Candidate Id</flux:label>
                <flux:input wire:model="candidateIdSearch" placeholder="e.g. 873504" />
            </flux:field>
            <flux:field>
                <flux:label>Candidate Last Name</flux:label>
                <flux:input wire:model="lastNameSearch" placeholder="e.g. Danner" />
            </flux:field>
            <div class="sm:col-span-2">
                <flux:button type="submit" variant="primary">Search</flux:button>
            </div>
        </form>

        @if (! empty($matches))
            <div class="mt-4 space-y-1">
                <flux:text size="sm" class="font-medium text-zinc-500">Multiple candidates matched — select one:</flux:text>
                @foreach ($matches as $match)
                    <button type="button" wire:click="selectCandidate({{ $match['id'] }})" class="block w-full text-left px-3 py-2 rounded-md text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">
                        {{ $match['name'] }} — {{ $match['voicePart'] }} — #{{ $match['id'] }}
                    </button>
                @endforeach
            </div>
        @endif
    </flux:card>

    @if ($candidate)
        {{-- Basic bio --}}
        <flux:card class="mb-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="space-y-1 text-sm">
                    <div><span class="text-zinc-500">Id:</span> <span class="font-medium">{{ $candidate->ref }}</span></div>
                    <div><span class="text-zinc-500">Name:</span> <span class="font-medium">{{ $candidate->student->user->name }}</span></div>
                    <div><span class="text-zinc-500">Teacher:</span> <span class="font-medium">{{ $candidate->teacher?->user?->name ?? '—' }}</span></div>
                    <div><span class="text-zinc-500">School:</span> <span class="font-medium">{{ $candidate->school?->name ?? '—' }}</span></div>
                    <div><span class="text-zinc-500">Voice Part:</span> <span class="font-medium">{{ $candidate->voicePart->name }}</span></div>
                </div>

                <div>
                    <flux:field>
                        <flux:label>Change voice part</flux:label>
                        <div class="flex gap-2">
                            <flux:select wire:model="newVoicePartId">
                                <flux:select.option value="">— select —</flux:select.option>
                                @foreach ($availableVoiceParts as $vp)
                                    <flux:select.option value="{{ $vp->id }}">{{ $vp->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:modal.trigger name="change-voice-part">
                                <flux:button>Change voice part</flux:button>
                            </flux:modal.trigger>
                        </div>
                        <flux:error name="newVoicePartId" />
                    </flux:field>
                </div>
            </div>
        </flux:card>

        {{-- Room selection --}}
        <flux:card class="mb-4">
            <flux:heading size="lg" class="mb-2">Current scores for room with (tolerance)</flux:heading>

            @if ($rooms->isEmpty())
                <flux:text size="sm" class="text-zinc-500">This candidate's voice part isn't assigned to any Room yet.</flux:text>
            @else
                <div class="flex flex-wrap gap-4 mb-3">
                    @foreach ($rooms as $room)
                        <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                            <input type="radio" wire:click="selectRoom({{ $room->id }})" @checked($selectedRoom?->id === $room->id) name="room" class="text-zinc-800 focus:ring-zinc-800 dark:text-zinc-200">
                            {{ $room->name }} ({{ $room->tolerance ?? '—' }})
                        </label>
                    @endforeach
                </div>

                @if ($selectedRoom && $judgeSummary->isNotEmpty())
                    <flux:heading size="base" class="mb-2">Judge Summary Table</flux:heading>

                    @unless ($inTolerance)
                        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-2">
                            <flux:callout.text>Scores are out of the room's {{ $selectedRoom->tolerance }}-point tolerance.</flux:callout.text>
                        </flux:callout>
                    @endunless

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm {{ $inTolerance ? '' : 'bg-red-50 dark:bg-red-950/30' }}">
                            <thead>
                                <tr>
                                    <th class="text-left px-2 py-1">Name</th>
                                    @foreach ($rubric as $category)
                                        @foreach ($category->scoreFactors as $factor)
                                            <th class="px-2 py-1 text-center text-xs text-zinc-500">{{ $factor->abbreviation }}</th>
                                        @endforeach
                                    @endforeach
                                    <th class="px-2 py-1 text-center">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($judgeSummary as $row)
                                    @php $rowScores = $roomScores->get($row['judge']->id, collect())->keyBy('score_factor_id'); @endphp
                                    <tr class="{{ $selectedJudge?->id === $row['judge']->id ? 'font-semibold' : '' }}">
                                        <td class="px-2 py-1">{{ $row['judge']->user->name }}</td>
                                        @foreach ($rubric as $category)
                                            @foreach ($category->scoreFactors as $factor)
                                                <td class="px-2 py-1 text-center">{{ $rowScores->get($factor->id)?->score ?? '—' }}</td>
                                            @endforeach
                                        @endforeach
                                        <td class="px-2 py-1 text-center font-medium">{{ $row['enteredCount'] > 0 ? $row['total'] : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </flux:card>

        @if ($selectedRoom)
            {{-- Judge selection + score form --}}
            <flux:card>
                <flux:heading size="lg" class="mb-2">Form for updating {{ $candidate->student->user->name }}'s scores</flux:heading>

                <div class="flex flex-wrap gap-4 mb-4">
                    @foreach ($roomJudgesOrdered as $judge)
                        <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                            <input type="radio" wire:click="selectJudge({{ $judge->id }})" @checked($selectedJudge?->id === $judge->id) name="judge" class="text-zinc-800 focus:ring-zinc-800 dark:text-zinc-200">
                            {{ $judge->user->name }}
                        </label>
                    @endforeach
                </div>

                @if ($selectedJudge)
                    <flux:heading size="base" class="mb-2">Adjudication Form for: {{ $candidate->ref }}</flux:heading>
                    <flux:text size="sm" class="text-zinc-500 mb-3">Judge: {{ strtoupper($selectedJudge->user->name) }}</flux:text>

                    <form
                        wire:submit="save"
                        x-data="{
                            scores: @entangle('scores'),
                            multipliers: {{ \Illuminate\Support\Js::from($rubric->flatMap(fn ($category) => $category->scoreFactors)->pluck('multiplier', 'id')) }},
                            get total() {
                                let entries = Object.entries(this.scores).filter(([, v]) => v !== '' && v !== null && v !== undefined);
                                if (entries.length === 0) return '--';
                                return entries.reduce((sum, [factorId, value]) => sum + (Number(value) * Number(this.multipliers[factorId] ?? 1)), 0);
                            }
                        }"
                        class="space-y-4"
                    >
                        @foreach ($rubric as $category)
                            <fieldset class="border border-zinc-200 dark:border-zinc-700 rounded-md p-3">
                                <legend class="px-1 text-sm font-medium">{{ $category->description }}</legend>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    @foreach ($category->scoreFactors as $factor)
                                        <flux:field wire:key="score-field-{{ $selectedJudge->id }}-{{ $factor->id }}">
                                            <flux:label>{{ $factor->description }} ({{ $factor->abbreviation }})</flux:label>
                                            <flux:select wire:model.live="scores.{{ $factor->id }}">
                                                <flux:select.option value="">— select —</flux:select.option>
                                                @foreach ($factorOptions[$factor->id] as $option)
                                                    <flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>
                                                @endforeach
                                            </flux:select>
                                            <flux:error name="scores.{{ $factor->id }}" />
                                        </flux:field>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endforeach

                        <flux:text class="font-medium">Total: <span x-text="total"></span></flux:text>

                        @if ($errors->any())
                            <flux:callout variant="danger" icon="exclamation-triangle">
                                <flux:callout.text>Select a score for every factor before saving.</flux:callout.text>
                            </flux:callout>
                        @endif

                        <flux:button type="submit" variant="primary">Save</flux:button>
                    </form>
                @endif
            </flux:card>
        @endif
    @endif

    {{-- Change voice part confirmation modal --}}
    <flux:modal name="change-voice-part" class="w-full max-w-md">
        <flux:heading size="lg" class="mb-4">Change Voice Part</flux:heading>

        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4">
            <flux:callout.text>
                NOTE: Changing voice parts after auditions have started will remove ALL previously entered scores and any non-relevant recordings for this candidate.
            </flux:callout.text>
        </flux:callout>

        <div class="flex justify-end gap-3">
            <flux:modal.close>
                <flux:button variant="ghost">Cancel</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" wire:click="changeVoicePart">Change voice part</flux:button>
        </div>
    </flux:modal>
</div>
