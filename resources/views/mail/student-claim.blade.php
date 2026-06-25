<x-mail::message>
# Request to add your student: {{ $student->user->name }}

{{ $requestingTeacher->user->first_name }} {{ $requestingTeacher->user->last_name }} has requested to add your student **{{ $student->user->name }}** as their student at **{{ $school->name }}** on {{ config('app.name') }}. If this is the same {{ $student->user->name }} you teach, you can approve the request below.

<x-mail::button :url="$approveUrl">
Approve request
</x-mail::button>

If there is no reason to move this student, you can deny it instead:

<x-mail::button :url="$denyUrl" color="error">
Deny request
</x-mail::button>

If you didn't expect this, you can safely ignore this email — nothing changes until one of the links above is clicked.

@env('local')
**Local testing URLs (not HTML-encoded):**
Approve: {!! $approveUrl !!}
Deny: {!! $denyUrl !!}
@endenv

 Note: These links will expire on {{ $expiresAt }}.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
