<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
</head>
<body>
    <h1>Ensemble Participation &mdash; {{ $ensemble->name }}</h1>
    <p class="subheading">{{ $version->event->name }} &mdash; {{ $version->name }}</p>

    @if ($rows->isEmpty())
        <p class="empty">No accepted candidates for this Ensemble yet.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Grade</th>
                    <th>Voice Part</th>
                    <th>School</th>
                    <th>Teacher</th>
                    <th>Emergency Contact</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['candidate']->student->user->sort_name }}</td>
                        <td>{{ $row['candidate']->student->grade ?? '—' }}</td>
                        <td>{{ $row['candidate']->voicePart?->abbr ?? '—' }}</td>
                        <td>{{ $row['candidate']->school->name ?? '—' }}</td>
                        <td>
                            {{ $row['candidate']->teacher->user->name }}
                            <div class="contact-detail">{{ $row['candidate']->teacher->user->email }}</div>
                        </td>
                        <td>
                            {{ $row['candidate']->emergencyContact->name ?? '—' }}
                            @if ($row['candidate']->emergencyContact?->preferred_phone)
                                <div class="contact-detail">{{ $row['candidate']->emergencyContact->preferred_phone }}</div>
                            @endif
                        </td>
                        <td>{{ $row['total'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
