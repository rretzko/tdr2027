<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
</head>
<body>
    <h1>Adjudication Backup — {{ $label }}</h1>
    <p class="subheading">{{ $version->event->name }} &mdash; {{ $version->name }}</p>

    <div class="placeholder">No in-person audition is scheduled for the current season. This is a placeholder — real per-candidate room assignment has not been built yet.</div>

    @if ($rooms->isNotEmpty())
        <table>
            <thead>
                <tr>
                    <th>Room</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rooms as $room)
                    <tr>
                        <td>{{ $room->name }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
