@props(['version', 'summary', 'compact' => false, 'onChecklist' => false])

{{--
    Version setup progress (App\Services\Readiness\VersionReadiness::summary()).
    Full card on VersionEdit; compact bar on the Events Show version list.
--}}
@php
    $percent = $summary['percent'];
    $barColor = $percent === 100 ? 'bg-green-500 dark:bg-green-400' : 'bg-sky-500 dark:bg-sky-400';
    $blockingTotal = collect($summary['phases'])->sum('blocking');
@endphp

@if ($compact)
    <a href="{{ route('events.versions.readiness', $version) }}" wire:navigate {{ $attributes->merge(['class' => 'group flex items-center gap-2 min-w-32']) }} title="Setup progress — open checklist">
        <div class="h-1.5 flex-1 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
            <div class="h-full rounded-full {{ $barColor }}" style="width: {{ $percent }}%"></div>
        </div>
        <span class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400 group-hover:text-zinc-800 dark:group-hover:text-zinc-200">{{ $summary['done'] }}/{{ $summary['total'] }}</span>
    </a>
@else
    <flux:card {{ $attributes->merge(['class' => 'space-y-4']) }} :id="$onChecklist ? 'tour-setup-progress' : null">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="lg">Setup progress</flux:heading>
                <flux:text size="sm" class="text-zinc-500">
                    {{ $summary['done'] }} of {{ $summary['total'] }} done
                    @if ($summary['needsReview'] > 0)
                        · {{ $summary['needsReview'] }} to review
                    @endif
                    @if ($blockingTotal > 0)
                        · {{ $blockingTotal }} still required
                    @endif
                </flux:text>
            </div>
            @unless ($onChecklist)
                <flux:button size="sm" variant="primary" icon="clipboard-document-check" href="{{ route('events.versions.readiness', $version) }}" wire:navigate>
                    Setup checklist
                </flux:button>
            @endunless
        </div>

        <div class="h-2 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
            <div class="h-full rounded-full {{ $barColor }}" style="width: {{ $percent }}%"></div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2">
            @foreach ($summary['phases'] as $phaseSummary)
                @continue($phaseSummary['total'] === 0)
                <a href="{{ $onChecklist ? '' : route('events.versions.readiness', $version) }}#phase-{{ $phaseSummary['phase']->value }}" @if ($onChecklist) id="tour-phase-{{ $phaseSummary['phase']->value }}" @else wire:navigate @endif
                   class="rounded-lg border px-3 py-2 text-sm border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                    <div class="text-zinc-600 dark:text-zinc-300">{{ $phaseSummary['phase']->label() }}</div>
                    <div class="flex items-center gap-2 mt-0.5">
                        <span class="font-medium tabular-nums text-zinc-900 dark:text-zinc-100">{{ $phaseSummary['done'] }}/{{ $phaseSummary['total'] }}</span>
                        @if ($phaseSummary['blocking'] > 0)
                            <span class="text-xs text-red-600 dark:text-red-400">{{ $phaseSummary['blocking'] }} required</span>
                        @elseif ($phaseSummary['done'] === $phaseSummary['total'])
                            <flux:icon.check-circle variant="mini" class="text-green-600 dark:text-green-400" />
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    </flux:card>
@endif
