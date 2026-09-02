<div>
    <div class="mb-6">
        <a href="{{ route('registrations.results-index') }}" wire:navigate class="text-sm text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
            &larr; Results
        </a>

        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mt-1">
            <div>
                <flux:heading size="xl">{{ $version->name }}</flux:heading>
                <flux:text size="sm" class="text-zinc-500">{{ $version->event->name }}</flux:text>
            </div>

            <div class="flex flex-col sm:flex-row gap-2">
                @if ($schoolOptions->count() > 1)
                    <flux:select wire:model.live="schoolFilter" placeholder="All schools" class="sm:max-w-xs">
                        <flux:select.option value="">All schools</flux:select.option>
                        @foreach ($schoolOptions as $school)
                            <flux:select.option value="{{ $school->id }}">{{ $school->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                @if ($switcherOptions->count() > 1)
                    <flux:select wire:model.live="switchVersionId" class="sm:max-w-xs">
                        @foreach ($switcherOptions as $option)
                            <flux:select.option value="{{ $option->id }}">{{ $option->event->name }} — {{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>
        </div>
    </div>

    @if ($schoolReports->isNotEmpty() || $shareResultsEnabled)
        <div class="flex flex-wrap gap-2 mb-6">
            @foreach ($schoolReports as $report)
                <div class="flex items-center gap-1 rounded-lg border border-zinc-200 dark:border-zinc-700 pl-3 pr-1 py-1">
                    <flux:text size="sm">{{ $report['school']->name }} Score Report</flux:text>
                    <flux:modal.trigger name="school-report-{{ $report['school']->id }}">
                        <flux:button size="sm" variant="ghost" icon="eye" aria-label="View {{ $report['school']->name }} score report" wire:click="$refresh" />
                    </flux:modal.trigger>
                    <flux:button size="sm" variant="ghost" icon="arrow-down-tray" aria-label="Download {{ $report['school']->name }} score report PDF" :href="route('registrations.results.school-report-pdf', ['version' => $version, 'school' => $report['school']])" target="_blank" />
                </div>
            @endforeach

            @if ($shareResultsEnabled)
                <div class="flex items-center gap-1 rounded-lg border border-zinc-200 dark:border-zinc-700 pl-3 pr-1 py-1">
                    <flux:text size="sm">Full Score Report</flux:text>
                    @if ($shareResultsReady)
                        <flux:button size="sm" variant="ghost" icon="arrow-down-tray" aria-label="Download full score report PDF" :href="route('registrations.results.shared-scores-pdf', $version)" target="_blank" />
                    @else
                        <flux:badge size="sm" color="zinc">Preparing…</flux:badge>
                    @endif
                </div>
            @endif
        </div>
    @endif

    @if ($candidates->isEmpty())
        <flux:callout variant="info" icon="information-circle">
            <flux:callout.text>No audition results for this event yet.</flux:callout.text>
        </flux:callout>
    @else
        @php
            $accepted = fn ($candidate) => $candidate->getRawOriginal('status') === 'accepted';
        @endphp

        {{-- Cards below md:, table at md:+ --}}
        <div class="md:hidden space-y-3">
            @foreach ($candidates as $index => $candidate)
                <flux:card size="sm">
                    <div class="flex items-center gap-3">
                        <flux:text class="text-zinc-500 tabular-nums">{{ $index + 1 }}</flux:text>
                        <div>
                            <flux:heading size="base">{{ $candidate->student->user->sort_name }}</flux:heading>
                            @if ($schoolOptions->count() > 1)
                                <flux:text size="sm" class="text-zinc-500">{{ $candidate->school?->name }}</flux:text>
                            @endif
                        </div>
                        <flux:spacer />
                        <flux:badge size="sm">{{ $candidate->voicePart?->abbr ?? '—' }}</flux:badge>
                    </div>
                    <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                        <div>
                            <flux:text size="sm" class="text-zinc-500">Score</flux:text>
                            <flux:text>{{ $candidate->auditionResult?->total ?? '—' }}</flux:text>
                        </div>
                        <div>
                            <flux:text size="sm" class="text-zinc-500">Result</flux:text>
                            @if ($accepted($candidate))
                                <flux:badge size="sm" color="green">Accepted</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ $candidate->status->label() }}</flux:badge>
                            @endif
                        </div>
                        <div>
                            <flux:text size="sm" class="text-zinc-500">Ensemble</flux:text>
                            <flux:text>{{ $candidate->acceptedEnsemble?->name ?? '—' }}</flux:text>
                        </div>
                    </div>

                    @if ($candidateReports->has($candidate->id))
                        <div class="mt-3 flex justify-end gap-1">
                            <flux:modal.trigger name="candidate-report-{{ $candidate->id }}">
                                <flux:button size="sm" variant="ghost" icon="eye" aria-label="View score report" wire:click="$refresh" />
                            </flux:modal.trigger>
                            <flux:button size="sm" variant="ghost" icon="arrow-down-tray" aria-label="Download score report PDF" :href="route('registrations.results.candidate-report-pdf', [$version, $candidate])" target="_blank" />
                        </div>
                    @endif
                </flux:card>
            @endforeach
        </div>

        <flux:table class="hidden md:table">
            <flux:table.columns>
                <flux:table.column class="w-12">#</flux:table.column>
                <flux:table.column sortable :sorted="$sortColumn === 'name'" :direction="$sortDirection" wire:click="sortBy('name')">Name</flux:table.column>
                @if ($schoolOptions->count() > 1)
                    <flux:table.column>School</flux:table.column>
                @endif
                <flux:table.column align="center" sortable :sorted="$sortColumn === 'voice_part'" :direction="$sortDirection" wire:click="sortBy('voice_part')">Voice Part</flux:table.column>
                <flux:table.column align="center" sortable :sorted="$sortColumn === 'score'" :direction="$sortDirection" wire:click="sortBy('score')">Score</flux:table.column>
                <flux:table.column align="center" sortable :sorted="$sortColumn === 'result'" :direction="$sortDirection" wire:click="sortBy('result')">Result</flux:table.column>
                <flux:table.column align="center" sortable :sorted="$sortColumn === 'ensemble'" :direction="$sortDirection" wire:click="sortBy('ensemble')">Ensemble</flux:table.column>
                <flux:table.column align="center">Report</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($candidates as $index => $candidate)
                    <flux:table.row>
                        <flux:table.cell class="tabular-nums text-zinc-500">{{ $index + 1 }}</flux:table.cell>
                        <flux:table.cell class="font-medium">{{ $candidate->student->user->sort_name }}</flux:table.cell>
                        @if ($schoolOptions->count() > 1)
                            <flux:table.cell>{{ $candidate->school?->name }}</flux:table.cell>
                        @endif
                        <flux:table.cell align="center">{{ $candidate->voicePart?->abbr ?? '—' }}</flux:table.cell>
                        <flux:table.cell align="center" class="tabular-nums">{{ $candidate->auditionResult?->total ?? '—' }}</flux:table.cell>
                        <flux:table.cell align="center">
                            @if ($accepted($candidate))
                                <flux:badge size="sm" color="green">Accepted</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ $candidate->status->label() }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="center">{{ $candidate->acceptedEnsemble?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell align="center">
                            @if ($candidateReports->has($candidate->id))
                                <div class="flex justify-center gap-1">
                                    <flux:modal.trigger name="candidate-report-{{ $candidate->id }}">
                                        <flux:button size="sm" variant="ghost" icon="eye" aria-label="View score report" wire:click="$refresh" />
                                    </flux:modal.trigger>
                                    <flux:button size="sm" variant="ghost" icon="arrow-down-tray" aria-label="Download score report PDF" :href="route('registrations.results.candidate-report-pdf', [$version, $candidate])" target="_blank" />
                                </div>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    @foreach ($schoolReports as $report)
        <flux:modal name="school-report-{{ $report['school']->id }}" class="w-full max-w-7xl">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $report['school']->name }}</flux:heading>
                    <flux:subheading>Score Report — {{ $version->name }}</flux:subheading>
                </div>

                @foreach ($report['pages'] as $page)
                    @if (! $loop->first)
                        <flux:separator />
                    @endif
                    @include('livewire.registrations.partials.candidate-score-report', ['candidate' => $page['candidate'], 'table' => $page['table']])
                @endforeach
            </div>
        </flux:modal>
    @endforeach

    @foreach ($candidateReports as $page)
        <flux:modal name="candidate-report-{{ $page['candidate']->id }}" class="w-full max-w-7xl">
            @include('livewire.registrations.partials.candidate-score-report', ['candidate' => $page['candidate'], 'table' => $page['table']])
        </flux:modal>
    @endforeach
</div>
