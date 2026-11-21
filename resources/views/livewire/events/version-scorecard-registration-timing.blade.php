@php
    // Same validated categorical slots as <x-scorecard-trend-chart>:
    // by the student deadline = 1 (blue), between the deadlines = 3 (green),
    // after the teacher cut-off = 2 (orange).
    $phaseColors = [
        'early' => ['bar' => 'text-[#2a78d6] dark:text-[#3987e5]', 'dot' => 'bg-[#2a78d6] dark:bg-[#3987e5]'],
        'middle' => ['bar' => 'text-[#1baf7a] dark:text-[#199e70]', 'dot' => 'bg-[#1baf7a] dark:bg-[#199e70]'],
        'late' => ['bar' => 'text-[#eb6834] dark:text-[#d95926]', 'dot' => 'bg-[#eb6834] dark:bg-[#d95926]'],
    ];
@endphp

<div class="mb-10">
    <flux:heading size="lg" class="mb-1">When students were registered</flux:heading>
    <flux:text size="sm" class="text-zinc-500 mb-3 max-w-3xl">
        Each registered student on the day they were first registered (Eastern time).
        @if ($timing['cutoff'] !== null)
            Days count to {{ $timing['cutoffName'] }} ({{ $timing['cutoff']->format('l, F j') }}); 0 is that day.
        @endif
        @if ($timing['studentDeadline'] !== null)
            The student deadline was {{ $timing['studentDeadline']->format('l, F j') }}.
        @endif
    </flux:text>

    <flux:card class="space-y-3">
        {{-- Phase summary --}}
        <dl class="flex flex-wrap gap-x-8 gap-y-2">
            @foreach ($timing['phases'] as $phase)
                <div wire:key="timing-phase-{{ $phase['field'] }}" class="flex items-center gap-2">
                    <span class="inline-block size-2.5 rounded-full {{ $phaseColors[$phase['field']]['dot'] }}"></span>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $phase['label'] }}</dt>
                    <dd class="text-sm font-semibold tabular-nums text-zinc-800 dark:text-white">
                        {{ number_format($phase['count']) }} ({{ $phase['percent'] }}%)
                    </dd>
                </div>
            @endforeach
        </dl>

        <flux:chart :value="$timing['rows']" class="aspect-[3/1]">
            <flux:chart.svg>
                <flux:chart.stack>
                    @foreach ($timing['phases'] as $phase)
                        <flux:chart.bar :field="$phase['field']" :class="$phaseColors[$phase['field']]['bar']" />
                    @endforeach
                </flux:chart.stack>
                <flux:chart.axis axis="x" field="x">
                    <flux:chart.axis.line />
                    <flux:chart.axis.tick />
                </flux:chart.axis>
                <flux:chart.axis axis="y" :format="['maximumFractionDigits' => 0]">
                    <flux:chart.axis.grid />
                    <flux:chart.axis.tick />
                </flux:chart.axis>
                <flux:chart.cursor />
            </flux:chart.svg>
            <flux:chart.tooltip>
                <flux:chart.tooltip.heading field="heading" />
                @foreach ($timing['phases'] as $phase)
                    <flux:chart.tooltip.value :field="$phase['field']" :label="$phase['label']">
                        <flux:chart.legend.indicator :class="$phaseColors[$phase['field']]['dot']" />
                    </flux:chart.tooltip.value>
                @endforeach
            </flux:chart.tooltip>
        </flux:chart>
    </flux:card>
</div>
