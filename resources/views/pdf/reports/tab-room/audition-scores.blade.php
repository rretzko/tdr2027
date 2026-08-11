<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
</head>
<body>
    <h1>Audition Scores &mdash; {{ $voicePart->name }} ({{ $voicePart->abbr }})</h1>
    <p class="subheading">{{ $version->event->name }} &mdash; {{ $version->name }}</p>

    @if ($rows->isEmpty())
        <p class="empty">No candidates found for this Voice Part.</p>
    @else
        @php
            // Category border wins outright where an edge is both a category and a judge boundary.
            $columnLeftClass = fn (array $column): string => $column['category_box'] && $column['is_category_start'] ? 'box-left' : ($column['is_judge_start'] ? 'judge-box-left' : '');
            $columnRightClass = fn (array $column): string => $column['category_box'] && $column['is_category_end'] ? 'box-right' : ($column['is_judge_end'] ? 'judge-box-right' : '');
            $columnBottomClass = fn (array $column): string => $column['category_box'] ? 'box-bottom' : 'judge-box-bottom';
        @endphp
        <table>
            <thead>
                <tr>
                    <th rowspan="2"></th>
                    @foreach ($categoryGroups as $group)
                        <th colspan="{{ $group['span'] }}" class="@if ($group['box']) box-top box-left box-right @endif @if ($group['shaded']) category-shade @endif">{{ $group['label'] }}</th>
                    @endforeach
                    <th rowspan="3">Total</th>
                    <th rowspan="3">Result</th>
                </tr>
                <tr>
                    @foreach ($judgeGroups as $group)
                        <th colspan="{{ $group['span'] }}" class="judge-box-top {{ $group['box'] && $group['is_category_start'] ? 'box-left' : 'judge-box-left' }} {{ $group['box'] && $group['is_category_end'] ? 'box-right' : 'judge-box-right' }} @if ($group['shaded']) category-shade @endif">{{ $group['label'] }}</th>
                    @endforeach
                </tr>
                <tr>
                    <th>Candidate #</th>
                    @foreach ($columns as $column)
                        <th class="{{ $columnLeftClass($column) }} {{ $columnRightClass($column) }} @if ($column['category_shaded']) category-shade @endif">{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['candidate']->id }}</td>
                        @foreach ($columns as $column)
                            <td class="{{ $columnLeftClass($column) }} {{ $columnRightClass($column) }} @if ($loop->parent->last) {{ $columnBottomClass($column) }} @endif @if ($column['category_shaded']) category-shade @endif">{{ $row['scores']["{$column['judge_id']}:{$column['score_factor_id']}"] ?? '—' }}</td>
                        @endforeach
                        <td>{{ $row['total'] }}</td>
                        <td>{{ $row['result'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
