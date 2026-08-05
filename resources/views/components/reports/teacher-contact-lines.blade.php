@props(['teacher'])

@php
    $email = $teacher->user->email;
    $cell = \App\Support\Reports\TeacherDisplay::cellPhoneFormatted($teacher);
    $work = \App\Support\Reports\TeacherDisplay::workPhoneFormatted($teacher);
@endphp

@if ($email)
    <div {{ $attributes->merge(['class' => 'pl-3 text-xs text-zinc-500 dark:text-zinc-400']) }}>{{ $email }}</div>
@endif
@if ($cell)
    <div {{ $attributes->merge(['class' => 'pl-3 text-xs text-zinc-500 dark:text-zinc-400']) }}>{{ $cell }}</div>
@endif
@if ($work)
    <div {{ $attributes->merge(['class' => 'pl-3 text-xs text-zinc-500 dark:text-zinc-400']) }}>{{ $work }}</div>
@endif
