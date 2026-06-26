<x-mail::message>
# Verify your school email

    Hi {{ $schoolTeacher->teacher->user->first_name }},

    Thank you for using TheDirectorsRoom.com!

    To maintain the integrity and confidentiality of your students' personal information, we periodically ask the
    teachers on the system to verify their school email address.

    We have {{ $schoolTeacher->school_email }} as your email address for **{{ $schoolTeacher->school->name }}** on TheDirectorsRoom.com.

    Please click below to confirm this address belongs to you.

<x-mail::button :url="$verificationUrl">
Verify school email
</x-mail::button>

    Note that your access to {{ $schoolTeacher->school->name }} students is prohibited until this verification is complete.

If you are no longer using TheDirectorsRoom.com, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
