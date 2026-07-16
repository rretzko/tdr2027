<x-mail::message>
# Invitation request: {{ $requestingTeacher->user->name }}

**{{ $requestingTeacher->user->name }}** has requested an invitation to register candidates for **{{ $version->name }}**.

<x-mail::table>
| | |
|:--|:--|
| School | {{ $schoolName ?? '—' }} |
| County | {{ $countyName ?? '—' }} |
| Email | {{ $requestingTeacher->user->email }} |
| Cell phone | {{ $requestingTeacher->user->cell_phone ?? '—' }} |
| Membership # | {{ $membershipNumber ?? '—' }} |
| Membership expires | {{ $membershipExpiresAt ?? '—' }} |
</x-mail::table>

<x-mail::button :url="$approveUrl">
Approve request
</x-mail::button>

If this teacher shouldn't be invited, you can deny it instead:

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
