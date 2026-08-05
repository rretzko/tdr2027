@php
    $email = $teacher->user->email;
    $cell = \App\Support\Reports\TeacherDisplay::cellPhoneFormatted($teacher);
    $work = \App\Support\Reports\TeacherDisplay::workPhoneFormatted($teacher);
@endphp

@if ($email)
    <div class="contact-detail">{{ $email }}</div>
@endif
@if ($cell)
    <div class="contact-detail">{{ $cell }}</div>
@endif
@if ($work)
    <div class="contact-detail">{{ $work }}</div>
@endif
