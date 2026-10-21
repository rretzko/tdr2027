@php
    $formatDate = fn (?string $raw): string => $raw === null
        ? ''
        // A plain "ET" rather than format('T'), which would mix EDT/EST
        // across a calendar that spans the end of Daylight Saving Time.
        : \Carbon\Carbon::parse($raw, 'UTC')->forDisplay()->format('D, M j, Y g:i A').' ET';
    $dateLabel = fn (\App\Models\VersionDate $date): string => match ($date->date_type) {
        \App\Enums\VersionDateType::Candidate => 'Candidate (StudentFolder.info)',
        default => $date->date_type?->label() ?? (string) $date->getRawOriginal('date_type'),
    };
@endphp
<x-mail::message>
# {{ $update->version->event->name }} — {{ $update->version->name }}

This email is automatically sent every Wednesday morning to directors participating in {{ $update->version->name }} with an update on their students' progress.

<hr style="border: none; border-top: 1px solid #e4e4e7; margin: 24px 0;">

**Invitation status:** {{ $update->invitationStatus->label() }}

<hr style="border: none; border-top: 1px solid #e4e4e7; margin: 24px 0;">

## Calendar

@if (count($update->dates) > 0)
| Date | Start | End |
| :--- | :--- | :--- |
@foreach ($update->dates as $date)
| {{ $dateLabel($date) }} | {{ $formatDate($date->getRawOriginal('start_at')) }} | {{ $formatDate($date->getRawOriginal('end_at')) }} |
@endforeach
@else
No dates have been published yet.
@endif

<hr style="border: none; border-top: 1px solid #e4e4e7; margin: 24px 0;">

## Students

| Status | Count |
| :--- | ---: |
| Eligible | {{ $update->eligibleStudents }} |
| Pending | {{ $update->pendingStudents }} |
| Registered | {{ $update->registeredStudents }} |

- **Pending:** Some value of the student's registration record has been updated.
- **Registered:** All required values for registration have been satisfied.

@if ($update->hasFees())
<hr style="border: none; border-top: 1px solid #e4e4e7; margin: 24px 0;">

## Fees

| Fee | Amount |
| :--- | ---: |
@if ($update->registrationFeeCents > 0)
| Registration Fee (per student) | {{ $update::dollars($update->registrationFeeCents) }} |
@endif
@if ($update->participationFeeCents > 0)
| Participation Fee (per student) | {{ $update::dollars($update->participationFeeCents) }} |
@endif
| Due ({{ $update->registeredStudents }} registered × Registration Fee) | {{ $update::dollars($update->dueCents) }} |
| Paid | {{ $update::dollars($update->paidCents) }} |
@if ($update->balanceDueCents() < 0)
| **Credit** | **{{ $update::dollars(-$update->balanceDueCents()) }}** |
@else
| **Balance Due** | **{{ $update::dollars($update->balanceDueCents()) }}** |
@endif
@endif

<hr style="border: none; border-top: 1px solid #e4e4e7; margin: 24px 0;">

## Pending Students

@if (count($update->pendingRoster) > 0)
| Name | Voice Part | Missing or Incomplete |
| :--- | :--- | :--- |
@foreach ($update->pendingRoster as $row)
| {{ $row['name'] }} | {{ $row['voicePart'] }} | {{ implode('; ', $row['missing']) }} |
@endforeach
@else
No pending students.
@endif

<hr style="border: none; border-top: 1px solid #e4e4e7; margin: 24px 0;">

## Registered Students

@if (count($update->registeredRoster) > 0)
| Name | Voice Part |
| :--- | :--- |
@foreach ($update->registeredRoster as $row)
| {{ $row['name'] }} | {{ $row['voicePart'] }} |
@endforeach
@else
No registered students yet.
@endif

<x-mail::button :url="route('registrations.version', $update->version)">
View your registrations
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
