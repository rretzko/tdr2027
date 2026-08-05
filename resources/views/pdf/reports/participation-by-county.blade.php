<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
</head>
<body>
    <h1>Participation by County</h1>
    <p class="subheading">{{ $version->event->name }} &mdash; {{ $version->name }}</p>

    @if ($rows->isEmpty())
        <p class="empty">No counties are in scope for this Version.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>County</th>
                    <th>Obligated Teachers</th>
                    <th>Participating Teachers</th>
                    <th>Candidates</th>
                    <th>Manager</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['county']->name }}</td>
                        <td>{{ $row['obligatedTeacherCount'] }}</td>
                        <td>{{ $row['participatingTeacherCount'] }}</td>
                        <td>{{ $row['candidateCount'] }}</td>
                        <td>{{ $row['managerName'] ?? '—' }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td class="group-header-school">Totals</td>
                    <td class="group-header-school">{{ $totals['obligatedTeacherCount'] }}</td>
                    <td class="group-header-school">{{ $totals['participatingTeacherCount'] }}</td>
                    <td class="group-header-school">{{ $totals['candidateCount'] }}</td>
                    <td class="group-header-school"></td>
                </tr>
            </tbody>
        </table>
    @endif
</body>
</html>
