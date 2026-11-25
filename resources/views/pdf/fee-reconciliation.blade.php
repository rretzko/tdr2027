<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
</head>
<body>
    @php $money = fn (int $cents): string => ($cents < 0 ? '-' : '').'$'.number_format(abs($cents) / 100, 2); @endphp
    <h1>{{ $title }}</h1>
    <p class="subheading">{{ $version->event->name }} &mdash; {{ $version->name }} &mdash; {{ $subheading }}</p>
    <p>
        Students: {{ $totals['count'] }} &mdash; Due: {{ $money($totals['dueCents']) }} &mdash;
        Waived: {{ $money($totals['waivedCents']) }} &mdash; Paid: {{ $money($totals['paidCents']) }} &mdash;
        Pending: {{ $money($totals['pendingCents']) }} &mdash; Balance: {{ $money($totals['balanceCents']) }}
    </p>

    @if ($rows->isEmpty())
        <p class="empty">No students.</p>
    @else
        <table>
            <thead>
                <tr><th>Student</th><th>School</th><th>Ensemble</th><th>Due</th><th>Paid</th><th>Balance</th><th>Payments</th></tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['candidate']->student->user->sort_name }}</td>
                        <td>{{ $row['candidate']->school->name ?? '—' }}</td>
                        <td>{{ $row['candidate']->acceptedEnsemble->name ?? '—' }}</td>
                        <td>{{ $row['waivedCents'] > 0 ? 'Waived' : $money($row['dueCents']) }}</td>
                        <td>{{ $money($row['paidCents']) }}</td>
                        <td>{{ $money($row['balanceCents']) }}</td>
                        <td>{{ \App\Services\FeeReconciliationService::paymentSummary($row['payments']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
