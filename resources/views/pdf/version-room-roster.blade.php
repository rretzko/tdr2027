<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page {
            margin: 24px;
        }

        body {
            font-family: sans-serif;
            font-size: 11px;
            color: #1a1a1a;
        }

        h1 {
            font-size: 18px;
            margin: 0 0 2px;
        }

        .subheading {
            font-size: 12px;
            color: #555;
            margin: 0 0 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        th, td {
            text-align: left;
            vertical-align: top;
            padding: 6px 8px;
            border: 1px solid #ccc;
        }

        th {
            background-color: #f2f2f2;
        }

        .room-name {
            font-weight: bold;
        }

        .empty {
            color: #999;
        }
    </style>
</head>
<body>
    <h1>Room Roster</h1>
    <p class="subheading">{{ $version->event->name }} &mdash; {{ $version->name }}</p>

    @if ($rooms->isEmpty())
        <p class="empty">No rooms have been added to this Version.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Room</th>
                    <th>Tolerance</th>
                    <th>Score Categories</th>
                    <th>Voice Parts</th>
                    <th>Judges</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rooms as $room)
                    <tr>
                        <td class="room-name">{{ $room->name }}</td>
                        <td>{{ $room->tolerance ?? '—' }}</td>
                        <td>
                            @if ($room->scoreCategories->isEmpty())
                                <span class="empty">&mdash;</span>
                            @else
                                {{ $room->scoreCategories->pluck('description')->join(', ') }}
                            @endif
                        </td>
                        <td>
                            @if ($room->voiceParts->isEmpty())
                                <span class="empty">&mdash;</span>
                            @else
                                {{ $room->voiceParts->pluck('name')->join(', ') }}
                            @endif
                        </td>
                        <td>
                            @if ($room->roomJudges->isEmpty())
                                <span class="empty">&mdash;</span>
                            @else
                                @foreach ($room->roomJudges as $roomJudge)
                                    {{ $roomJudge->judge_type->label() }}: {{ $roomJudge->user->name }}<br>
                                @endforeach
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
