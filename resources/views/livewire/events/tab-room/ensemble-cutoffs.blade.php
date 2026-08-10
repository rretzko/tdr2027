<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <a href="{{ route('events.versions.tab-room.index', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Tab Room</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Ensemble Cut-offs</span>
    </div>

    <div class="mb-6">
        <flux:heading size="xl">Ensemble Cut-offs</flux:heading>
        <flux:text size="sm" class="text-zinc-500">{{ $version->name }}</flux:text>
    </div>

    {{-- Accepted counts by Ensemble and Voice Part --}}
    <flux:card class="mb-4">
        <flux:heading size="lg" class="mb-2">Accepted Candidates</flux:heading>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left px-2 py-1">Ensemble</th>
                        @foreach ($voiceParts as $vp)
                            <th class="px-2 py-1 text-center">{{ $vp->abbr }}</th>
                        @endforeach
                        <th class="px-2 py-1 text-center font-semibold">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($ensembles as $ensemble)
                        <tr>
                            <td class="px-2 py-1 font-medium">
                                <span class="inline-block w-2.5 h-2.5 rounded-full me-1" style="background-color: {{ $ensembleColors[$ensemble->id] }}"></span>
                                {{ $ensemble->name }}
                            </td>
                            @foreach ($voiceParts as $vp)
                                <td class="px-2 py-1 text-center tabular-nums">{{ $countsByEnsembleVoicePart[$ensemble->id][$vp->id] ?? 0 }}</td>
                            @endforeach
                            <td class="px-2 py-1 text-center font-semibold tabular-nums">{{ array_sum($countsByEnsembleVoicePart[$ensemble->id] ?? []) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $voiceParts->count() + 2 }}" class="text-center text-zinc-500 py-4">No Ensembles configured for this Event.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>

    {{-- Ensemble Breakdown — left half: one pie per Ensemble, slices =
         accepted count per Voice Part this season. Slices are
         direct-labeled with the Voice Part's abbr (skipped on slices too
         small to hold text — see buildPieChart()'s docblock); the exact
         count/percent for every slice, labeled or not, is always reachable
         via hover/focus, and the full breakdown is already a table above
         (Accepted Candidates), so nothing is gated behind a label or a
         hover. Right half: History — prior seasons' counts, entered
         manually (there's no live source for them; see
         EnsembleHistoryService's docblock), plus a one-click snapshot of
         the current season so next year's "prior two seasons" doesn't need
         re-entry. --}}
    <flux:card class="mb-4">
        <flux:heading size="lg" class="mb-3">Ensemble Breakdown</flux:heading>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- This season's pies. History gets this season's counts
                 automatically when the audition is closed (VersionEdit's
                 general Status field → EnsembleHistoryService::
                 recordCurrentSeason()) — no manual save here, so late
                 corrections after a reopen are never missed. --}}
            <div>
                <flux:subheading class="mb-2">This Season</flux:subheading>

                @if ($pieCharts->sum('total') > 0)
                    <div class="flex flex-wrap gap-8">
                        @foreach ($pieCharts as $chart)
                            @continue($chart['total'] === 0)
                            <div class="flex flex-col items-center" x-data="{ hovered: null }" wire:key="pie-{{ $chart['ensemble']->id }}">
                                <flux:text class="font-semibold">{{ $chart['ensemble']->name }}</flux:text>
                                <flux:text size="sm" class="text-zinc-500 mb-2">{{ $chart['total'] }} accepted</flux:text>

                                <svg viewBox="0 0 200 200" class="w-44 h-44" role="img" aria-label="{{ $chart['ensemble']->name }} accepted candidates by voice part">
                                    @foreach ($chart['slices'] as $slice)
                                        <path
                                            d="{{ $slice['path'] }}"
                                            fill="{{ $slice['color'] }}"
                                            class="stroke-white dark:stroke-zinc-900 transition-opacity"
                                            stroke-width="2"
                                            stroke-linejoin="round"
                                            :class="hovered && hovered.label !== @js($slice['voicePart']->abbr) ? 'opacity-60' : ''"
                                            x-on:mouseenter="hovered = { label: @js($slice['voicePart']->abbr), count: {{ $slice['count'] }}, percent: @js($slice['percent']) }"
                                            x-on:mouseleave="hovered = null"
                                            x-on:focus="hovered = { label: @js($slice['voicePart']->abbr), count: {{ $slice['count'] }}, percent: @js($slice['percent']) }"
                                            x-on:blur="hovered = null"
                                            tabindex="0"
                                            role="img"
                                            aria-label="{{ $slice['voicePart']->name }}: {{ $slice['count'] }} ({{ $slice['percent'] }}%)"
                                        ><title>{{ $slice['voicePart']->name }}: {{ $slice['count'] }} ({{ $slice['percent'] }}%)</title></path>
                                        @if ($slice['showLabel'])
                                            <text
                                                x="{{ $slice['labelX'] }}"
                                                y="{{ $slice['labelY'] }}"
                                                fill="{{ $slice['labelColor'] }}"
                                                text-anchor="middle"
                                                dominant-baseline="central"
                                                class="text-[13px] font-semibold pointer-events-none select-none"
                                            >{{ $slice['voicePart']->abbr }}</text>
                                        @endif
                                    @endforeach
                                </svg>

                                {{-- Hover/focus readout — same information as the tooltip, always reachable without hovering via the table above. --}}
                                <div class="h-5 text-sm mt-1" x-text="hovered ? hovered.label + ': ' + hovered.count + ' (' + hovered.percent + '%)' : ''"></div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <flux:text size="sm" class="text-zinc-500">No accepted candidates yet this season.</flux:text>
                @endif
            </div>

            {{-- History — manual entry, one compact table per prior season
                 year. Total is a live client-side sum (Alpine, entangled
                 with historyInputs) so it updates as the manager types,
                 before Save is clicked — same @entangle pattern as the
                 Adjudicate page's score-total. --}}
            <div x-data="{ historyInputs: @entangle('historyInputs') }">
                <flux:subheading class="mb-2">History</flux:subheading>

                @foreach ($priorSeasonYears as $year)
                    <div class="mb-4 last:mb-0">
                        <flux:text class="font-medium block mb-1">{{ $year }}</flux:text>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr>
                                        <th class="text-left px-2 py-1">Ensemble</th>
                                        @foreach ($voiceParts as $vp)
                                            <th class="px-1 py-1 text-center text-xs">{{ $vp->abbr }}</th>
                                        @endforeach
                                        <th class="px-2 py-1 text-center text-xs font-semibold">Total</th>
                                        <th class="px-2 py-1"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($ensembles as $ensemble)
                                        @php $rowKeys = $voiceParts->map(fn ($vp) => "{$ensemble->id}_{$year}_{$vp->id}")->values(); @endphp
                                        <tr wire:key="history-{{ $ensemble->id }}-{{ $year }}">
                                            <td class="px-2 py-1 font-medium max-w-[9rem] truncate" title="{{ $ensemble->name }}">{{ $ensemble->name }}</td>
                                            @foreach ($voiceParts as $vp)
                                                @php $key = "{$ensemble->id}_{$year}_{$vp->id}"; @endphp
                                                <td class="px-0.5 py-1">
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        wire:model="historyInputs.{{ $key }}"
                                                        class="w-12 text-center text-xs rounded border-zinc-200 dark:border-zinc-700 dark:bg-zinc-800 focus:border-zinc-400 focus:ring-0"
                                                        aria-label="{{ $ensemble->name }} {{ $vp->name }} {{ $year }}"
                                                    />
                                                </td>
                                            @endforeach
                                            <td class="px-2 py-1 text-center font-semibold tabular-nums" x-text="@js($rowKeys).reduce((sum, k) => sum + (Number(historyInputs[k]) || 0), 0)"></td>
                                            <td class="px-2 py-1 text-right">
                                                <flux:button size="sm" variant="ghost" wire:click="saveHistoryRow({{ $ensemble->id }}, {{ $year }})">
                                                    Save
                                                </flux:button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="{{ $voiceParts->count() + 3 }}" class="text-center text-zinc-500 py-2 text-xs">No Ensembles configured for this Event.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </flux:card>

    {{-- Score Cut-offs — every Voice Part side by side in one shared-scroll
         grid, so counts can be compared across Voice Parts without paging
         through a separate full-width section per Voice Part. Each score is
         itself the "apply cutoff" button, matching the prior UI. A resolved
         Voice Part collapses to a compact summary; "Reopen" re-expands the
         full color-coded list without undoing anything — clicking a
         different score re-decides it in place. --}}
    <flux:card class="mb-4">
        <div class="flex items-center justify-between mb-2">
            <flux:heading size="lg">Score Cut-offs</flux:heading>
            @unless ($needsPerEnsemble)
                <flux:text size="sm" class="text-zinc-500">Click a score to apply that cutoff.</flux:text>
            @endunless
        </div>

        <div class="overflow-auto max-h-[36rem] border border-zinc-200 dark:border-zinc-700 rounded-md">
            <div class="flex w-max min-w-full">
                @foreach ($voicePartData as $data)
                    @php [$voicePart, $ranked, $allDecided, $isReopened, $acceptedCount, $notAcceptedCount] = [$data['voicePart'], $data['ranked'], $data['allDecided'], $data['isReopened'], $data['acceptedCount'], $data['notAcceptedCount']]; @endphp
                    <div class="w-28 shrink-0 border-e border-zinc-200 dark:border-zinc-700 last:border-e-0" wire:key="col-{{ $voicePart->id }}">
                        <div class="sticky top-0 z-10 bg-white dark:bg-zinc-900 px-2 py-1.5 border-b border-zinc-200 dark:border-zinc-700 text-center">
                            <flux:text size="sm" class="font-semibold">{{ $voicePart->abbr }}</flux:text>
                        </div>

                        @if ($ranked->isEmpty())
                            <flux:text size="sm" class="block text-zinc-500 text-center p-2">No scores yet</flux:text>
                        @elseif ($allDecided && ! $isReopened)
                            <div class="p-2 text-center">
                                <flux:icon.check-circle variant="mini" class="inline text-green-500" />
                                <flux:text size="sm" class="block text-zinc-500 mt-1">Resolved</flux:text>
                                <flux:text size="sm" class="block text-green-600 dark:text-green-400">{{ $acceptedCount }} in</flux:text>
                                <flux:text size="sm" class="block text-zinc-500">{{ $notAcceptedCount }} out</flux:text>
                                <flux:button size="sm" variant="ghost" class="mt-1 w-full" wire:click="reopenVoicePart({{ $voicePart->id }})">
                                    Reopen
                                </flux:button>
                            </div>
                        @else
                            <div>
                                @if ($allDecided && $isReopened)
                                    <button type="button" wire:click="collapseVoicePart({{ $voicePart->id }})" class="w-full px-2 py-1 text-center text-xs text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200 border-b border-zinc-100 dark:border-zinc-800">
                                        Collapse
                                    </button>
                                @endif

                                @foreach ($ranked as $row)
                                    @php
                                        $rowStatus = $row['candidate']->getRawOriginal('status');
                                        // Accepted rows take on the specific Ensemble's own
                                        // color (same palette as the summary table above), not
                                        // a generic "accepted" green — with
                                        // AlternatingEnsembleAssignment, different rows in the
                                        // same Voice Part land in different Ensembles.
                                        $rowColor = $rowStatus === 'accepted' ? ($ensembleColors[$row['candidate']->accepted_ensemble_id] ?? null) : null;
                                        $rowStyle = $rowColor ? "background-color: {$rowColor}1a; border-left: 3px solid {$rowColor};" : '';
                                        $rowClasses = match (true) {
                                            $rowColor !== null => '',
                                            $rowStatus === 'not_accepted' => 'bg-zinc-50 dark:bg-zinc-800/60 text-zinc-400 dark:text-zinc-500',
                                            default => '',
                                        };
                                    @endphp
                                    @if ($needsPerEnsemble)
                                        <div class="px-2 py-1 text-center text-sm tabular-nums border-b border-zinc-100 dark:border-zinc-800 {{ $rowClasses }}" style="{{ $rowStyle }}" wire:key="score-{{ $voicePart->id }}-{{ $row['candidate']->id }}">
                                            {{ $row['total'] }}
                                        </div>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="applyCutoff({{ $voicePart->id }}, {{ $row['total'] }})"
                                            wire:confirm="Apply a cutoff of {{ $row['total'] }} for {{ $voicePart->name }}? Candidates below this score will not be accepted."
                                            wire:key="score-{{ $voicePart->id }}-{{ $row['candidate']->id }}"
                                            style="{{ $rowStyle }}"
                                            class="w-full px-2 py-1 text-center text-sm tabular-nums border-b border-zinc-100 dark:border-zinc-800 hover:font-semibold {{ $rowClasses ?: ($rowColor ? '' : 'hover:bg-blue-50 dark:hover:bg-blue-900/30') }}"
                                        >
                                            {{ $row['total'] }}
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        @if (! $needsPerEnsemble)
            <div class="flex flex-wrap gap-3 mt-2 text-xs text-zinc-500">
                @foreach ($ensembles as $ensemble)
                    <span><span class="inline-block w-2.5 h-2.5 rounded-sm me-1" style="background-color: {{ $ensembleColors[$ensemble->id] }}"></span>Accepted — {{ $ensemble->name }}</span>
                @endforeach
                <span><span class="inline-block w-2.5 h-2.5 rounded-sm bg-zinc-100 dark:bg-zinc-800 me-1"></span>Not accepted</span>
            </div>
        @endif
    </flux:card>

    {{-- Per-Ensemble cutoff entry — only for strategies needing a distinct
         score per Ensemble (SequentialEnsembleFill, GradeSegmentedEnsembles).
         Laid out as compact cards that wrap, not one full-width card per
         Voice Part. Hidden once a Voice Part is fully decided, unless
         reopened. --}}
    @if ($needsPerEnsemble)
        <flux:card>
            <flux:heading size="lg" class="mb-2">Apply Ensemble Cutoffs</flux:heading>
            <flux:text size="sm" class="text-zinc-500 mb-3">Enter a cutoff for each Ensemble above, then finalize once every Ensemble has been applied.</flux:text>

            <div class="flex flex-wrap gap-3">
                @foreach ($voicePartData as $data)
                    @php [$voicePart, $ranked, $eligibleEnsembles, $allDecided, $isReopened] = [$data['voicePart'], $data['ranked'], $data['eligibleEnsembles'], $data['allDecided'], $data['isReopened']]; @endphp
                    @continue($ranked->isEmpty() || ($allDecided && ! $isReopened))

                    <div class="w-64 border border-zinc-200 dark:border-zinc-700 rounded-md p-3" wire:key="ensemble-form-{{ $voicePart->id }}">
                        <flux:text class="font-semibold block mb-2">{{ $voicePart->name }}</flux:text>

                        <div class="space-y-2">
                            @forelse ($eligibleEnsembles as $ensemble)
                                @php $key = "{$voicePart->id}_{$ensemble->id}"; @endphp
                                <div class="flex items-end gap-2" wire:key="ensemble-input-{{ $key }}">
                                    <flux:field class="flex-1">
                                        <flux:label class="text-xs">{{ $ensemble->name }}</flux:label>
                                        <flux:input size="sm" wire:model="ensembleCutoffInputs.{{ $key }}" type="number" />
                                    </flux:field>
                                    <flux:button size="sm" wire:click="applyEnsembleCutoff({{ $voicePart->id }}, {{ $ensemble->id }})" wire:confirm="Apply this cutoff for {{ $ensemble->name }}?">
                                        Apply
                                    </flux:button>
                                </div>
                            @empty
                                <flux:text size="sm" class="text-zinc-500">No Ensembles configured for this Voice Part yet.</flux:text>
                            @endforelse

                            <flux:button variant="danger" size="sm" class="w-full" wire:click="finalizeVoicePart({{ $voicePart->id }})" wire:confirm="Finalize {{ $voicePart->name }}? Every candidate not yet accepted into an Ensemble above will be marked not accepted.">
                                Finalize — reject remaining
                            </flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        </flux:card>
    @endif
</div>
