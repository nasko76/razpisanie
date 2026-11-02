<!doctype html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <title>Разписание PDF</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #333; padding: 8px; text-align: left; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>
    <h2>{{ $group->specialty->name }} - {{ $group->group_name }}</h2>

    <table>
        <thead>
            <tr>
                <th>Ден</th>
                <th>Седмица</th>
                <th>Предмет</th>
                <th>Преподавател</th>
                <th>Зала</th>
                <th>Час</th>
            </tr>
        </thead>
        <tbody>
            @foreach($entries as $entry)
                <tr>
                    <td>{{ $entry->weekday_no }}</td>
                    <td>{{ $entry->cycle_week }}</td>
                    <td>{{ $entry->subject->name }}</td>
                    <td>
                        @if($entry->teacher)
                            {{ $entry->teacher->first_name }} {{ $entry->teacher->last_name }}
                        @endif
                    </td>
                    <td>
                        @if($entry->room)
                            {{ $entry->room->name }}
                        @endif
                    </td>
                    <td>{{ $entry->start_time }} - {{ $entry->end_time }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>