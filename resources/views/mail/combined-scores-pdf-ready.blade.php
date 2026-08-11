<x-mail::message>
# Your Combined Audition Scores PDF is ready

The {{ $confidential ? 'confidential' : 'public' }} Combined Audition Scores PDF for every Ensemble in **{{ $version->name }}** is attached to this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
