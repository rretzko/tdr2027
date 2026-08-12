<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
</head>
<body>
    @php
        $columnLeftClass = fn (array $column): string => $column['category_box'] && $column['is_category_start'] ? 'box-left' : ($column['is_judge_start'] ? 'judge-box-left' : '');
        $columnRightClass = fn (array $column): string => $column['category_box'] && $column['is_category_end'] ? 'box-right' : ($column['is_judge_end'] ? 'judge-box-right' : '');
        $columnBottomClass = fn (array $column): string => $column['category_box'] ? 'box-bottom' : 'judge-box-bottom';
    @endphp
    @foreach ($pages as $page)
        @php $row = $page['table']['rows']->first(); @endphp
        <div @class(['page-break' => ! $loop->first])>
            <h1>{{ $page['candidate']->student->user->name }}</h1>
            <p class="subheading">{{ $page['candidate']->voicePart->name }} &mdash; {{ $version->event->name }} — {{ $version->name }}</p>

            <table>
                <thead>
                    <tr>
                        <th colspan="2" rowspan="2"></th>
                        @foreach ($page['table']['categoryGroups'] as $group)
                            <th colspan="{{ $group['span'] }}" class="@if ($group['box']) box-top box-left box-right @endif @if ($group['shaded']) category-shade @endif">{{ $group['label'] }}</th>
                        @endforeach
                        <th rowspan="3">Total</th>
                        <th rowspan="3">Result</th>
                    </tr>
                    <tr>
                        @foreach ($page['table']['judgeGroups'] as $group)
                            <th colspan="{{ $group['span'] }}" class="judge-box-top {{ $group['box'] && $group['is_category_start'] ? 'box-left' : 'judge-box-left' }} {{ $group['box'] && $group['is_category_end'] ? 'box-right' : 'judge-box-right' }} @if ($group['shaded']) category-shade @endif">{{ $group['label'] }}</th>
                        @endforeach
                    </tr>
                    <tr>
                        <th>Candidate #</th>
                        <th>VP</th>
                        @foreach ($page['table']['columns'] as $column)
                            <th class="{{ $columnLeftClass($column) }} {{ $columnRightClass($column) }} @if ($column['category_shaded']) category-shade @endif">{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{{ $page['candidate']->id }}</td>
                        <td>{{ $page['candidate']->voicePart->abbr }}</td>
                        @foreach ($page['table']['columns'] as $column)
                            <td class="{{ $columnLeftClass($column) }} {{ $columnRightClass($column) }} {{ $columnBottomClass($column) }} @if ($column['category_shaded']) category-shade @endif">{{ $row['scores']["{$column['judge_id']}:{$column['score_factor_id']}"] ?? '—' }}</td>
                        @endforeach
                        <td>{{ $row['total'] }}</td>
                        <td>{{ $row['result'] }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endforeach
</body>
</html>
