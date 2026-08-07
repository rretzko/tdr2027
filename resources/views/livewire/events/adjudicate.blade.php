<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Adjudicate</span>
    </div>

    <div class="mb-6">
        <flux:heading size="xl">Adjudicate</flux:heading>
        <flux:text size="sm" class="text-zinc-500">{{ $version->name }} — {{ $room->name }}</flux:text>
    </div>

    {{-- 1. Judge Bios --}}
    <flux:card class="mb-4" x-data="{ open: true }">
        <div class="flex items-center justify-between" :class="open ? 'mb-2' : ''">
            <flux:heading size="lg">Judges</flux:heading>
            <button type="button" @click="open = !open" class="text-sm text-blue-600 hover:underline dark:text-blue-400" x-text="open ? 'Hide' : 'Show'"></button>
        </div>
        <div x-show="open" class="space-y-2">
            @foreach ($roomJudgesOrdered as $bioJudge)
                @php
                    $bioTeacher = $bioJudge->user->teacher;
                    $bioSchool = $bioTeacher?->schools->firstWhere('pivot.is_active', true);
                @endphp
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <flux:badge size="sm" color="amber">{{ $bioJudge->judge_type->label() }}</flux:badge>
                    <span class="font-medium {{ $bioJudge->id === $roomJudge->id ? 'underline' : '' }}">{{ $bioJudge->user->name }}</span>
                    @if ($bioSchool)
                        <span class="text-zinc-500">{{ $bioSchool->name }}</span>
                    @endif
                    <span class="text-zinc-500">{{ $bioJudge->user->email }}</span>
                    @if ($bioJudge->user->cell_phone)
                        <span class="text-zinc-500">{{ $bioJudge->user->cell_phone }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    </flux:card>

    {{-- 2. Room Performance Indicator Bar --}}
    <flux:card class="mb-4" x-data="{ open: true }">
        <div class="flex items-center justify-between" :class="open ? 'mb-2' : ''">
            <flux:heading size="lg">Room Progress</flux:heading>
            <button type="button" @click="open = !open" class="text-sm text-blue-600 hover:underline dark:text-blue-400" x-text="open ? 'Hide' : 'Show'"></button>
        </div>
        <div x-show="open">
            <div class="flex h-6 w-full overflow-hidden rounded-md text-xs font-medium text-white">
                <div class="bg-green-500 flex items-center justify-center" style="width: {{ $percentages['completed'] }}%">{{ $counts['completed'] ?: '' }}</div>
                <div class="bg-yellow-500 flex items-center justify-center" style="width: {{ $percentages['partial'] }}%">{{ $counts['partial'] ?: '' }}</div>
                <div class="bg-red-500 flex items-center justify-center" style="width: {{ $percentages['none'] }}%">{{ $counts['none'] ?: '' }}</div>
                <div class="bg-zinc-900 flex items-center justify-center" style="width: {{ $percentages['error'] }}%">{{ $counts['error'] ?: '' }}</div>
            </div>
            <div class="flex flex-wrap gap-3 mt-2 text-xs text-zinc-500">
                <span><span class="inline-block w-2.5 h-2.5 rounded-sm bg-green-500 me-1"></span>Completed ({{ $counts['completed'] }})</span>
                <span><span class="inline-block w-2.5 h-2.5 rounded-sm bg-yellow-500 me-1"></span>Pending ({{ $counts['partial'] }})</span>
                <span><span class="inline-block w-2.5 h-2.5 rounded-sm bg-red-500 me-1"></span>Non-adjudicated ({{ $counts['none'] }})</span>
                <span><span class="inline-block w-2.5 h-2.5 rounded-sm bg-zinc-900 me-1"></span>Error ({{ $counts['error'] }})</span>
            </div>
        </div>
    </flux:card>

    {{-- 2.a Roster of Candidates --}}
    <flux:card class="mb-4">
        <flux:heading size="lg" class="mb-2">Candidates ({{ $candidates->count() }})</flux:heading>

        @if ($candidates->isEmpty())
            <flux:text size="sm" class="text-zinc-500">No registered candidates in this room's voice part(s) yet.</flux:text>
        @else
            @php $currentVoicePartId = null; @endphp
            @foreach ($candidates as $candidate)
                @if ($candidate->voice_part_id !== $currentVoicePartId)
                    @php $currentVoicePartId = $candidate->voice_part_id; @endphp
                    <flux:text size="sm" class="font-medium text-zinc-500 mt-3 mb-1">{{ $candidate->voicePart->name }}</flux:text>
                @endif

                <button
                    type="button"
                    wire:click="selectCandidate({{ $candidate->id }})"
                    @class([
                        'inline-flex w-24 items-center justify-center gap-1 rounded-md px-2 py-1.5 me-1.5 mb-1.5 text-sm font-medium tabular-nums align-top',
                        'ring-2 ring-offset-1 ring-zinc-800 dark:ring-white' => $selectedCandidate?->id === $candidate->id,
                        'bg-green-500 text-white' => $statuses[$candidate->id] === 'completed',
                        'bg-yellow-500 text-black' => $statuses[$candidate->id] === 'partial',
                        'bg-red-500 text-white' => $statuses[$candidate->id] === 'none',
                        'bg-zinc-900 text-white' => $statuses[$candidate->id] === 'error',
                    ])
                >
                    <span @class(['w-3 shrink-0 text-center font-bold', 'text-red-200' => true, 'invisible' => $tolerances[$candidate->id] ?? true]) title="Out of tolerance">*</span>
                    <span class="flex-1 text-center">{{ $candidate->id }}</span>
                    <span @class(['w-3 shrink-0 flex justify-center', 'invisible' => ! ($judgeCompletion[$candidate->id] ?? false)])>
                        <flux:icon.check variant="micro" />
                    </span>
                </button>
            @endforeach

            <div class="flex flex-wrap gap-3 mt-3 pt-2 border-t border-zinc-200 dark:border-zinc-700 text-xs text-zinc-500">
                <span><span class="text-red-500 font-bold">*</span> Out of tolerance</span>
                <span class="inline-flex items-center gap-1"><flux:icon.check variant="micro" /> You've scored this candidate</span>
            </div>
        @endif
    </flux:card>

    @if ($selectedCandidate)
        {{-- 3. Optional Recordings Section --}}
        @if ($showRecordings)
            <flux:card class="mb-4 lg:max-w-[50%]">
                <flux:heading size="lg" class="mb-2">Recordings — Candidate {{ $selectedCandidate->id }}</flux:heading>

                @if ($recordings->isEmpty())
                    <flux:text size="sm" class="text-zinc-500">No approved recordings yet.</flux:text>
                @else
                    <div @class(['grid grid-cols-1 gap-3', 'lg:grid-cols-2' => $recordings->count() > 1])>
                        @foreach ($recordings as $recording)
                            <div wire:key="recording-{{ $recording->id }}">
                                <flux:text class="font-medium capitalize">{{ $recording->file_type }}</flux:text>
                                <audio controls preload="metadata" class="w-full">
                                    <source src="{{ \Illuminate\Support\Facades\Storage::disk('s3')->url($recording->url) }}">
                                </audio>
                            </div>
                        @endforeach
                    </div>
                @endif
            </flux:card>
        @endif

        {{-- 4 & 5. Adjudication form and Room Scoring table — always capped at
             50% width at lg:+ (a lone Score form naturally occupies just the
             first grid column when Room Scores isn't rendered yet) --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
            {{-- 4. Adjudication form --}}
            <flux:card>
                <flux:heading size="lg" class="mb-2">Score — Candidate {{ $selectedCandidate->id }}</flux:heading>

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
                            @php $legendFactor = $category->scoreFactors->first(); @endphp
                            <legend class="px-1 text-sm font-medium">
                                {{ $category->description }}
                                @if ($legendFactor !== null)
                                    <span class="font-normal text-zinc-500">— Best: {{ $legendFactor->best }}, Worst: {{ $legendFactor->worst }}, Tolerance: {{ $room->tolerance ?? '—' }}</span>
                                @endif
                            </legend>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                @foreach ($category->scoreFactors as $factor)
                                    <flux:field>
                                        <flux:label>{{ $factor->description }} ({{ $factor->abbreviation }})</flux:label>
                                        <flux:select wire:model="scores.{{ $factor->id }}">
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

                    <div class="flex gap-2">
                        <flux:button type="submit" variant="primary">Save Scores</flux:button>
                        <flux:button type="button" variant="ghost" wire:click="nextCandidate">Next candidate</flux:button>
                    </div>
                </form>
            </flux:card>

            {{-- 5. Room Scoring table --}}
            @if ($roomScores->isNotEmpty())
                @php $inTolerance = $tolerances[$selectedCandidate->id] ?? true; @endphp
                <flux:card>
                    <flux:heading size="lg" class="mb-2">Room Scores</flux:heading>

                    @unless ($inTolerance)
                        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-2">
                            <flux:callout.text>Scores are out of the room's {{ $room->tolerance }}-point tolerance.</flux:callout.text>
                        </flux:callout>
                    @endunless

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm {{ $inTolerance ? '' : 'bg-red-50 dark:bg-red-950/30' }}">
                            <thead>
                                <tr>
                                    <th class="text-left px-2 py-1"></th>
                                    @foreach ($rubric as $category)
                                        <th colspan="{{ $category->scoreFactors->count() }}" class="px-2 py-1 text-center border-b border-zinc-200 dark:border-zinc-700">{{ $category->description }}</th>
                                    @endforeach
                                    <th class="px-2 py-1 text-center border-b border-zinc-200 dark:border-zinc-700">Total</th>
                                </tr>
                                <tr>
                                    <th class="text-left px-2 py-1">Judge</th>
                                    @foreach ($rubric as $category)
                                        @foreach ($category->scoreFactors as $factor)
                                            <th class="px-2 py-1 text-center text-xs text-zinc-500">{{ $factor->abbreviation }}</th>
                                        @endforeach
                                    @endforeach
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($roomJudgesOrdered as $rowJudge)
                                    @php
                                        $judgeScores = $roomScores->get($rowJudge->id, collect())->keyBy('score_factor_id');
                                        $rowTotal = 0;
                                    @endphp
                                    <tr class="{{ $rowJudge->id === $roomJudge->id ? 'font-semibold' : '' }}">
                                        <td class="px-2 py-1">{{ $rowJudge->user->name }}</td>
                                        @foreach ($rubric as $category)
                                            @foreach ($category->scoreFactors as $factor)
                                                @php
                                                    $cell = $judgeScores->get($factor->id);
                                                    $rowTotal += $cell !== null ? $cell->score * $factor->multiplier : 0;
                                                @endphp
                                                <td class="px-2 py-1 text-center">{{ $cell !== null ? $cell->score : '—' }}</td>
                                            @endforeach
                                        @endforeach
                                        <td class="px-2 py-1 text-center font-medium">{{ $rowTotal }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </flux:card>
            @endif
        </div>
    @endif
</div>
