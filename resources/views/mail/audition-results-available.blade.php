<x-mail::message>
# Audition results are available

The audition results for your registered candidates in **{{ $version->name }}** are now available.

<x-mail::button :url="route('registrations.results', $version)">
View Results
</x-mail::button>

Thanks,<br>
{{ $closedBy->name }}, Event Manager {{ $version->name }}
</x-mail::message>
