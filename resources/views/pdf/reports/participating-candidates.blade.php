<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
</head>
<body>
    <h1>Participating Candidates</h1>
    <p class="subheading">{{ $version->event->name }} &mdash; {{ $version->name }}</p>

    @if ($rows->isEmpty())
        <p class="empty">No candidates match the current filters.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>School</th>
                    <th>Teacher</th>
                    <th>Candidate</th>
                    <th>Grade</th>
                    <th>Voice Part</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    @php $candidate = $row['candidate']; @endphp
                    <tr>
                        <td>{{ $candidate->school->name ?? '—' }}</td>
                        <td>{{ $candidate->teacher->user->name }}</td>
                        <td>{{ $candidate->student->user->name }}</td>
                        <td>{{ $row['grade'] ?? '—' }}</td>
                        <td>{{ $candidate->voicePart->name }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
