<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.partials.report-styles')
</head>
<body>
    <h1>Combined Audition Scores {{ $confidential ? '(Confidential)' : '(Public)' }}</h1>
    <p class="subheading">{{ $version->event->name }} &mdash; {{ $version->name }}</p>

    @if ($ensembleTables->isEmpty())
        <p class="empty">No Ensembles configured.</p>
    @else
        @php
            // Category border wins outright where an edge is both a category and a judge boundary.
            $columnLeftClass = fn (array $column): string => $column['category_box'] && $column['is_category_start'] ? 'box-left' : ($column['is_judge_start'] ? 'judge-box-left' : '');
            $columnRightClass = fn (array $column): string => $column['category_box'] && $column['is_category_end'] ? 'box-right' : ($column['is_judge_end'] ? 'judge-box-right' : '');
            $columnBottomClass = fn (array $column): string => $column['category_box'] ? 'box-bottom' : 'judge-box-bottom';
        @endphp
        @foreach ($ensembleTables as $ensembleTable)
            <h1>{{ $ensembleTable['sectionLabel'] }} ({{ $ensembleTable['voicePartTables']->sum(fn (array $t): int => $t['rows']->count()) }})</h1>

            @if ($ensembleTable['voicePartTables']->isEmpty())
                <p class="empty">No Voice Parts configured.</p>
            @else
                @foreach ($ensembleTable['voicePartTables'] as $table)
                    <h2>{{ $table['voicePart']->name }} ({{ $table['voicePart']->abbr }}) @ {{ $table['rows']->count() }}</h2>

                    @if ($table['rows']->isEmpty())
                        <p class="empty">No candidates for this Voice Part.</p>
                    @else
                        <table>
                            <thead>
                                <tr>
                                    <th colspan="{{ ($confidential ? 1 : 0) + 2 }}" rowspan="2"></th>
                                    @foreach ($table['categoryGroups'] as $group)
                                        <th colspan="{{ $group['span'] }}" class="@if ($group['box']) box-top box-left box-right @endif @if ($group['shaded']) category-shade @endif">{{ $group['label'] }}</th>
                                    @endforeach
                                    <th rowspan="3">Total</th>
                                    <th rowspan="3">Result</th>
                                </tr>
                                <tr>
                                    @foreach ($table['judgeGroups'] as $group)
                                        <th colspan="{{ $group['span'] }}" class="judge-box-top {{ $group['box'] && $group['is_category_start'] ? 'box-left' : 'judge-box-left' }} {{ $group['box'] && $group['is_category_end'] ? 'box-right' : 'judge-box-right' }} @if ($group['shaded']) category-shade @endif">{{ $group['label'] }}</th>
                                    @endforeach
                                </tr>
                                <tr>
                                    <th>Candidate #</th>
                                    @if ($confidential)
                                        <th>Student / School / Teacher</th>
                                    @endif
                                    <th>VP</th>
                                    @foreach ($table['columns'] as $column)
                                        <th class="{{ $columnLeftClass($column) }} {{ $columnRightClass($column) }} @if ($column['category_shaded']) category-shade @endif">{{ $column['label'] }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($table['rows'] as $row)
                                    <tr>
                                        <td>{{ $row['candidate']->id }}</td>
                                        @if ($confidential)
                                            <td>
                                                {{ $row['candidate']->student->user->sort_name }}
                                                <div class="contact-detail">{{ \Illuminate\Support\Str::limit($row['candidate']->school->name ?? '—', 25) }}</div>
                                                <div class="contact-detail">{{ $row['candidate']->teacher->user->name }}</div>
                                            </td>
                                        @endif
                                        <td>{{ $row['candidate']->voicePart->abbr }}</td>
                                        @foreach ($table['columns'] as $column)
                                            <td class="{{ $columnLeftClass($column) }} {{ $columnRightClass($column) }} @if ($loop->parent->last) {{ $columnBottomClass($column) }} @endif @if ($column['category_shaded']) category-shade @endif">{{ $row['scores']["{$column['judge_id']}:{$column['score_factor_id']}"] ?? '—' }}</td>
                                        @endforeach
                                        <td>{{ $row['total'] }}</td>
                                        <td>{{ $row['result'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @endforeach
            @endif
        @endforeach
    @endif
</body>
</html>
