<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
</head>
<body>
    <h1>Participating Schools</h1>
    <p class="subheading">{{ $version->event->name }} &mdash; {{ $version->name }}</p>

    @if ($rows->isEmpty())
        <p class="empty">No schools match the current filters.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>School</th>
                    <th>Teacher</th>
                    <th>Contact</th>
                    <th>Packet</th>
                    <th>Registered</th>
                    <th>Due</th>
                    <th>Paid</th>
                    <th>Balance</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['school']->name ?? '—' }}</td>
                        <td>{{ $row['teacher']->user->name }}</td>
                        <td>{{ $row['teacher']->user->email }}<br>{{ $row['phones'] }}</td>
                        <td>{{ $row['packet']?->isReceived() ? 'Received' : 'Outstanding' }}</td>
                        <td>{{ $row['count'] }}</td>
                        <td>${{ number_format($row['dueCents'] / 100, 2) }}</td>
                        <td>${{ number_format($row['paidCents'] / 100, 2) }}</td>
                        <td>
                            @if ($row['balanceCents'] > 0)
                                ${{ number_format($row['balanceCents'] / 100, 2) }} due
                            @elseif ($row['balanceCents'] < 0)
                                ${{ number_format(abs($row['balanceCents']) / 100, 2) }} overpaid
                            @else
                                Paid in full
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
