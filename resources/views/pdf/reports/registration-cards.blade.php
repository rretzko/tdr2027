<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
</head>
<body>
    <h1>Registration Cards</h1>
    <p class="subheading">{{ $version->event->name }} &mdash; {{ $version->name }}</p>

    <div class="placeholder">No in-person audition is scheduled for the current season. This is a placeholder — real registration card layout has not been built yet.</div>

    @if ($candidates->isNotEmpty())
        <table>
            <thead>
                <tr>
                    <th>Candidate</th>
                    <th>Id</th>
                    <th>School</th>
                    <th>Voice Part</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($candidates as $candidate)
                    <tr>
                        <td>{{ $candidate->student->user->name }}</td>
                        <td>{{ $candidate->ref }}</td>
                        <td>{{ $candidate->school->name ?? '—' }}</td>
                        <td>{{ $candidate->voicePart->name }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
