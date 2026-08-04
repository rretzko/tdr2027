<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
</head>
<body>
    <h1>Obligated Teachers</h1>
    <p class="subheading">{{ $version->event->name }} &mdash; {{ $version->name }}</p>

    @if ($rows->isEmpty())
        <p class="empty">No teachers have accepted the Version obligations yet.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Teacher</th>
                    <th>Phone(s)</th>
                    <th>Email</th>
                    <th>Accepted</th>
                    <th>School</th>
                    <th>Membership Expires</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['teacher']->user->name }}</td>
                        <td>{{ $row['phones'] }}</td>
                        <td>{{ $row['teacher']->user->email }}</td>
                        <td>{{ $row['decidedAt']?->forDisplay()->format('n/j/Y') ?? '—' }}</td>
                        <td>{{ $row['school']->name ?? '—' }}</td>
                        <td>{{ $row['membershipExpiresAt']?->format('n/j/Y') ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
