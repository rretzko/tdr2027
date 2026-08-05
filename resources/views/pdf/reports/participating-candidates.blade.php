<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
</head>
<body>
    <h1>Participating Candidates</h1>
    <p class="subheading">{{ $version->event->name }} &mdash; {{ $version->name }}</p>

    @if ($schoolGroups->isEmpty())
        <p class="empty">No candidates match the current filters.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Candidate</th>
                    <th>Grade</th>
                    <th>Voice Part</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($schoolGroups as $schoolGroup)
                    <tr>
                        <td colspan="3" class="group-header-school">{{ $schoolGroup['school']->name ?? '—' }}</td>
                    </tr>
                    @foreach ($schoolGroup['teacherGroups'] as $teacherGroup)
                        <tr>
                            <td colspan="3" class="group-header-teacher">
                                {{ $teacherGroup['teacher']->user->name }}
                                @include('pdf.reports.partials.teacher-contact-lines', ['teacher' => $teacherGroup['teacher']])
                            </td>
                        </tr>
                        @foreach ($teacherGroup['candidates'] as $row)
                            @php $candidate = $row['candidate']; @endphp
                            <tr>
                                <td class="candidate-name">{{ $candidate->student->user->name }}</td>
                                <td>{{ $row['grade'] ?? '—' }}</td>
                                <td>{{ $candidate->voicePart->name }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
