@props(['percent'])

@php
    $percent = max(0, min(100, (int) $percent));
@endphp

<div {{ $attributes->class('h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700') }} role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}">
    <div class="h-full rounded-full {{ $percent === 100 ? 'bg-emerald-500 dark:bg-emerald-400' : 'bg-sky-600 dark:bg-sky-400' }}" style="width: {{ $percent }}%"></div>
</div>
