<x-mail::message>
# You're invited to {{ $version->name }}

Good news — your request to register candidates for **{{ $version->name }}** has been approved. You can now access the Registration pages for this Version.

<x-mail::button :url="route('registrations.version', $version)">
Go to Registration
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
