<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
</head>
<body>
    <h1>Candidate Counts</h1>
    <p class="subheading">{{ $version->event->name }} &mdash; {{ $version->name }}</p>

    <p>
        @foreach ($voiceParts as $voicePart)
            {{ $voicePart->name }}: {{ $summary[$voicePart->id] }}&nbsp;&nbsp;
        @endforeach
        <strong>Total: {{ array_sum($summary) }}</strong>
    </p>

    @if ($rows->isEmpty())
        <p class="empty">No candidates match the current filters.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>School</th>
                    <th>Teacher</th>
                    <th>Phone(s)</th>
                    @foreach ($voiceParts as $voicePart)
                        <th>{{ $voicePart->name }}</th>
                    @endforeach
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['school']->name ?? '—' }}</td>
                        <td>{{ $row['teacher']->user->name }}</td>
                        <td>{{ $row['phones'] }}</td>
                        @foreach ($voiceParts as $voicePart)
                            <td>{{ $row['countsByVoicePart'][$voicePart->id] }}</td>
                        @endforeach
                        <td>{{ $row['total'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
