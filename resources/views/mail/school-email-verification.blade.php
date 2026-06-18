<x-mail::message>
# Verify your school email

{{ $schoolTeacher->teacher->user->first_name }} {{ $schoolTeacher->teacher->user->last_name }} added this address as their school email for **{{ $schoolTeacher->school->name }}** on {{ config('app.name') }}.

Click below to confirm this address belongs to you and unlock access to student data at this school.

<x-mail::button :url="$verificationUrl">
Verify school email
</x-mail::button>

If you didn't request this, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
