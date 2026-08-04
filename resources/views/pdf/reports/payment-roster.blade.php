<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
</head>
<body>
    <h1>Payment Roster</h1>
    <p class="subheading">{{ $version->event->name }} &mdash; {{ $version->name }}</p>

    @if ($rows->isEmpty())
        <p class="empty">No payments match the current filters.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>School</th>
                    <th>Teacher</th>
                    <th>Candidate</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Reference</th>
                    <th>Comments</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['schoolName'] ?? '—' }}</td>
                        <td>{{ $row['teacherName'] }}</td>
                        <td>{{ $row['candidateName'] ?? '—' }}</td>
                        <td>{{ $row['paymentType']->label() }}</td>
                        <td>${{ number_format($row['amountCents'] / 100, 2) }}</td>
                        <td>{{ $row['referenceNumber'] ?? '—' }}</td>
                        <td>{{ $row['comments'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
