<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
    <style>
        .year-yes { background-color: #dcfce7; text-align: center; }
        .year-no { background-color: #fee2e2; text-align: center; }
    </style>
</head>
<body>
    <h1>Student Seniority &mdash; {{ $ensemble->name }}</h1>
    <p class="subheading">{{ $version->event->name }} &mdash; {{ $version->name }}</p>

    @if ($rows->isEmpty())
        <p class="empty">No accepted candidates for this Ensemble yet.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>School</th>
                    <th>Teacher</th>
                    <th>Voice Part</th>
                    @foreach ($years as $year)
                        <th>{{ $year }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['candidate']->student->user->sort_name }}</td>
                        <td>{{ $row['candidate']->school->name ?? '—' }}</td>
                        <td>{{ $row['candidate']->teacher->user->name }}</td>
                        <td>{{ $row['candidate']->voicePart?->abbr ?? '—' }}</td>
                        @foreach ($years as $year)
                            <td class="{{ $row['years'][$year] ? 'year-yes' : 'year-no' }}">{{ $row['years'][$year] ? 'Y' : 'N' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
