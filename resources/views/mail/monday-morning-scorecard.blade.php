<x-mail::message>
# {{ $version->event->name }} — {{ $version->name }}

This email is automatically sent to {{ $version->name }} Event Managers every Monday morning, until adjudication begins, to highlight Event progress.

## Invitations

| Metric | Count |
| :--- | ---: |
| Invited Teachers | {{ $metrics->invitedTeachers }} |
| Invited Schools | {{ $metrics->invitedSchools }} |
| Eligible Students | {{ $metrics->eligibleStudents }} |

## Engaged

| Metric | Count |
| :--- | ---: |
| Obligated Teachers | {{ $metrics->obligatedTeachers }} |
| Obligated Schools | {{ $metrics->obligatedSchools }} |
| Engaged Students | {{ $metrics->engagedStudents }} |

## Registered

| Metric | Count |
| :--- | ---: |
| Registered Teachers | {{ $metrics->registeredTeachers }} |
| Registered Schools | {{ $metrics->registeredSchools }} |
| Registered Students | {{ $metrics->registeredStudents }} |

## Fees

| Metric | Amount |
| :--- | ---: |
| Registration Fees Due | ${{ number_format($metrics->registrationFeesDueInDollars(), 2) }} |
| Registration Fees Paid | ${{ number_format($metrics->registrationFeesPaidInDollars(), 2) }} |
| Registration Fees Outstanding | ${{ number_format($metrics->registrationFeesOutstandingInDollars(), 2) }} |

<x-mail::button :url="route('events.versions.scorecard', $version)">
View weekly trends
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}

---

Please let Rick know (rick@mfrholdings.com or use the Feedback feature in TheDirectorsRoom.com) if there are other metrics that you want to see added to this Scorecard or if there are any errors.

<hr style="border: none; border-top: 1px solid #e4e4e7; margin: 24px 0;">

Don't want the Monday Morning Scorecard? Unsubscribing stops it for every event; you can turn it back on under Settings → Email Preferences.

<x-mail::button :url="$unsubscribeUrl">
Unsubscribe
</x-mail::button>
</x-mail::message>
