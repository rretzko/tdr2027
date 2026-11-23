@props(['charts'])

{{-- One pie per Ensemble (App\Support\Charts\EnsemblePieCharts). Every
     slice's exact count/percent is reachable via hover/focus and its
     accessible name, so nothing is gated behind a label. --}}
@if (collect($charts)->sum('total') > 0)
    <div class="flex flex-wrap gap-8">
        @foreach ($charts as $chart)
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

                <div class="h-5 text-sm mt-1" x-text="hovered ? hovered.label + ': ' + hovered.count + ' (' + hovered.percent + '%)' : ''"></div>
            </div>
        @endforeach
    </div>
@else
    <flux:text size="sm" class="text-zinc-500">No accepted candidates yet this season.</flux:text>
@endif
