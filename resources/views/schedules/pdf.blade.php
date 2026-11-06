<!doctype html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <title>Разписание PDF</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; padding: 20px; font-size: 11px; color: #17231d; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #d3ddd6; padding: 8px; text-align: left; }
        th { background: #eaf3ed; }
        h1 { margin: 0 0 5px; font-size: 19px; }
        p { margin: 0; color: #66756c; }
    </style>
</head>
<body>
    <h1>{{ $group->specialty->name }} · {{ $group->display_name }}</h1>
    <p>{{ $academicYear }} · {{ $semesterType === 'winter' ? 'Зимен семестър' : 'Летен семестър' }} · Учебен план: {{ $group->curriculumVersion?->name ?? '—' }}</p>
    @if($note)
        <p style="margin-top:8px">{{ $note }}</p>
    @endif

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
                    <td>{{ $weekdays[$entry->weekday_no] ?? $entry->weekday_no }}</td>
                    <td>{{ $entry->cycle_week }}</td>
                    <td>{{ $entry->subject?->name ?? $entry->custom_subject_name }} @if($entry->note)<br><small>{{ $entry->note }}</small>@endif</td>
                    <td>
                        {{ implode(', ', $teacherNamesBySubject[$entry->subject_id] ?? ($entry->teacher ? [($entry->coverage_type === 'lecture' ? 'Лекции: ' : ($entry->coverage_type === 'exercise' ? 'Упражнения: ' : '')).trim(($entry->teacher->title ? $entry->teacher->title.' ' : '').$entry->teacher->first_name.' '.$entry->teacher->last_name)] : [])) }}
                    </td>
                    <td>
                        @if($entry->room)
                            {{ $entry->room->name }}
                        @endif
                    </td>
                    <td>{{ substr($entry->start_time, 0, 5) }}–{{ substr($entry->end_time, 0, 5) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>