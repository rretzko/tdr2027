<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
</head>
<body>
    <h1>Payment Reconciliation</h1>
    <p class="subheading">{{ $version->event->name }} &mdash; {{ $version->name }}</p>

    <p>
        Total due: ${{ number_format($versionTotals['dueCents'] / 100, 2) }} &mdash;
        Total paid: ${{ number_format($versionTotals['paidCents'] / 100, 2) }} &mdash;
        Balance:
        @if ($versionTotals['balanceCents'] > 0)
            ${{ number_format($versionTotals['balanceCents'] / 100, 2) }} due
        @elseif ($versionTotals['balanceCents'] < 0)
            ${{ number_format(abs($versionTotals['balanceCents']) / 100, 2) }} overpaid
        @else
            Paid in full
        @endif
    </p>

    @if ($schoolRows->isEmpty())
        <p class="empty">No schools match the current filters.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>School</th>
                    <th>Registered</th>
                    <th>Due</th>
                    <th>Paid</th>
                    <th>Balance</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($schoolRows as $row)
                    <tr>
                        <td>{{ $row['school']->name ?? '—' }}</td>
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
