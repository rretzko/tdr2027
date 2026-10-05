@props([
    'title',
    'rows',
    'series',
    'currency' => false,
])

@php
    // Categorical slots validated (light on white, dark on zinc-900) with the
    // dataviz palette checker. Same stage keeps the same slot across every
    // chart: invited/due = 1, obligated/engaged = 2, registered/paid = 3.
    $slots = [
        1 => ['line' => 'text-[#2a78d6] dark:text-[#3987e5]', 'dot' => 'bg-[#2a78d6] dark:bg-[#3987e5]'],
        2 => ['line' => 'text-[#eb6834] dark:text-[#d95926]', 'dot' => 'bg-[#eb6834] dark:bg-[#d95926]'],
        3 => ['line' => 'text-[#1baf7a] dark:text-[#199e70]', 'dot' => 'bg-[#1baf7a] dark:bg-[#199e70]'],
    ];

    $valueFormat = $currency ? ['style' => 'currency', 'currency' => 'USD'] : ['maximumFractionDigits' => 0];
@endphp

<flux:card class="space-y-3">
    <flux:heading size="sm">{{ $title }}</flux:heading>

    <flux:chart :value="$rows" class="aspect-[2/1]">
        <flux:chart.svg>
            @foreach ($series as $s)
                <flux:chart.line :field="$s['field']" :class="$slots[$s['slot']]['line']" curve="none" />
                <flux:chart.point :field="$s['field']" :class="$slots[$s['slot']]['line']" />
            @endforeach

            <flux:chart.axis axis="x" field="date" :format="['month' => 'short', 'day' => 'numeric']">
                <flux:chart.axis.line />
                <flux:chart.axis.tick />
            </flux:chart.axis>

            <flux:chart.axis axis="y" :format="$valueFormat">
                <flux:chart.axis.grid />
                <flux:chart.axis.tick />
            </flux:chart.axis>

            <flux:chart.cursor />
        </flux:chart.svg>

        <flux:chart.tooltip>
            <flux:chart.tooltip.heading field="date" :format="['month' => 'short', 'day' => 'numeric', 'year' => 'numeric']" />
            @foreach ($series as $s)
                <flux:chart.tooltip.value :field="$s['field']" :label="$s['label']" :format="$valueFormat">
                    <flux:chart.legend.indicator :class="$slots[$s['slot']]['dot']" />
                </flux:chart.tooltip.value>
            @endforeach
        </flux:chart.tooltip>
    </flux:chart>

    <div class="flex flex-wrap justify-center gap-x-4">
        @foreach ($series as $s)
            <flux:chart.legend :label="$s['label']">
                <flux:chart.legend.indicator :class="$slots[$s['slot']]['dot']" />
            </flux:chart.legend>
        @endforeach
    </div>
</flux:card>
