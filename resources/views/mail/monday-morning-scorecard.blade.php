<x-mail::message>
# {{ $version->event->name }} — {{ $version->name }}

This email is automatically sent to {{ $version->name }} Event Managers every Monday morning to highlight Event progress.

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

Thanks,<br>
{{ config('app.name') }}

---

Please let Rick know (rick@mfrholdings.com or use the Feedback feature in TheDirectorsRoom.com) if there are other metrics that you want to see added to this Scorecard or if there are any errors.
</x-mail::message>
