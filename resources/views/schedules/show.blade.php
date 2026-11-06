<!doctype html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Разписание</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 24px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #d1d5db; padding: 10px; text-align: left; }
        th { background: #f3f4f6; }
        a { display: inline-block; margin-bottom: 18px; color: #1d4ed8; text-decoration: none; font-weight: 600; }
        h1 { margin-bottom: 8px; }
    </style>
</head>
<body>
    <h1>{{ $group->specialty->name }} - {{ $group->display_name }}</h1>

    <a href="{{ route('schedules.export-pdf', ['group_id' => $group->id]) }}">Експорт PDF</a>

    <table>
        <thead>
            <tr>
                <th>Ден</th>
                <th>Седмица</th>
                <th>Начало</th>
                <th>Край</th>
                <th>Предмет</th>
                <th>Преподавател</th>
                <th>Зала</th>
            </tr>
        </thead>
        <tbody>
            @foreach($entries as $entry)
                <tr>
                    <td>{{ $entry->weekday_no }}</td>
                    <td>{{ $entry->cycle_week }}</td>
                    <td>{{ $entry->start_time }}</td>
                    <td>{{ $entry->end_time }}</td>
                    <td>{{ $entry->subject?->name ?? $entry->custom_subject_name }}</td>
                    <td>
                        @if($entry->teacher)
                            {{ $entry->teacher->first_name }} {{ $entry->teacher->last_name }}
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        @if($entry->room)
                            {{ $entry->room->name }}
                        @else
                            -
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>