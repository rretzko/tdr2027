<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
</head>
<body>
    <h1>Payment Register</h1>
    <p class="subheading">{{ $version->event->name }} &mdash; {{ $version->name }} &mdash; {{ $teacher->user->name }}</p>

    @if ($rows->isEmpty())
        <p class="empty">No payments recorded yet.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Candidate</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Reference</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['candidate']->student->user->sort_name }}</td>
                        <td>{{ $row['paidAt']->format('M j, Y') }}</td>
                        <td>{{ $row['type'] }}</td>
                        <td>{{ $row['amountCents'] < 0 ? '-' : '' }}${{ number_format(abs($row['amountCents']) / 100, 2) }}</td>
                        <td>{{ $row['referenceNumber'] ?? '—' }}</td>
                        <td>{{ $row['status']->label() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
