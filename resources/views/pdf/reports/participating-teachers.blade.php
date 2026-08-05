<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
</head>
<body>
    <h1>Participating Teachers</h1>
    <p class="subheading">{{ $version->event->name }} &mdash; {{ $version->name }}</p>

    @if ($rows->isEmpty())
        <p class="empty">No teachers have registered candidates yet.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Teacher</th>
                    <th>School</th>
                    <th>Registered</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>
                            {{ $row['teacher']->user->name }}
                            @include('pdf.reports.partials.teacher-contact-lines', ['teacher' => $row['teacher']])
                        </td>
                        <td>{{ $row['school']->name ?? '—' }}</td>
                        <td>{{ $row['count'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
