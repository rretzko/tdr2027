<x-mail::message>
# {{ $version->event->name }} — {{ $version->name }}

This is a system-generated email to advise you that you have an event going live within the next five days. The status of the event must be changed to "Active" if you want your teachers to be able to get to the event pages.

Use the following steps to change the status:

- Log into TheDirectorsRoom.com
- Click the Events link
- Click the Versions button
- Click the Configure button
- Select the appropriate status under the Status box

<x-mail::button :url="$configureUrl">
Configure {{ $version->name }}
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
