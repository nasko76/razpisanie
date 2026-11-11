<!doctype html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Сверка на преподавателски възлагания · ФИИ</title>
    <style>
        :root { --ink:#17231d; --muted:#6b7771; --line:#e3e9e5; --paper:#fff; --canvas:#f3f6f3; --green:#176b4a; --soft:#e8f4ed; --red:#a64b20; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--canvas); color:var(--ink); font:14px/1.45 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        a { color:inherit; }
        .shell { width:100%; max-width:1920px; margin:auto; padding:24px clamp(14px,3vw,48px) 48px; }
        .topbar,.heading { display:flex; align-items:center; justify-content:space-between; gap:16px; }
        .topbar { margin-bottom:30px; color:var(--muted); font-size:13px; }
        .heading { align-items:end; margin-bottom:22px; }
        h1 { margin:5px 0; font-size:clamp(25px,3vw,34px); letter-spacing:-.04em; }
        h2 { margin:0; font-size:15px; }
        .muted { color:var(--muted); font-size:11px; }
        .card { overflow:hidden; border:1px solid var(--line); border-radius:14px; background:var(--paper); box-shadow:0 6px 24px rgba(32,54,41,.035); }
        .filters { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; padding:16px; background:#fbfcfb; }
        label { display:block; min-width:0; color:var(--muted); font-size:11px; font-weight:700; }
        input,select { display:block; width:100%; min-height:38px; margin-top:5px; padding:0 10px; border:1px solid var(--line); border-radius:8px; background:#fff; color:var(--ink); }
        button,.button { display:inline-flex; min-height:38px; align-items:center; justify-content:center; padding:0 12px; border:1px solid var(--line); border-radius:9px; background:#fff; font:inherit; font-weight:650; text-decoration:none; cursor:pointer; }
        .filters .button { align-self:end; }
        .table-wrap { overflow:auto; }
        table { width:100%; border-collapse:collapse; text-align:left; }
        th,td { padding:11px 12px; border-top:1px solid var(--line); vertical-align:top; }
        th { background:#fbfcfb; color:var(--muted); font-size:11px; }
        td { font-size:12px; }
        .status { display:inline-block; padding:3px 8px; border-radius:20px; background:var(--soft); color:var(--green); font-size:10px; font-weight:750; white-space:nowrap; }
        .status.warn { background:#fff1e8; color:var(--red); }
        .empty { padding:20px; color:var(--muted); }
        @media(max-width:900px) { .filters { grid-template-columns:repeat(2,minmax(0,1fr)); } }
        @media(max-width:520px) { .filters { grid-template-columns:1fr; } .heading,.topbar { align-items:flex-start; flex-direction:column; } }
    </style>
</head>
<body>
<main class="shell">
    <header class="topbar">
        <a href="{{ route('catalog.index', ['tab' => 'teachers']) }}">← Към учебния каталог</a>
        <a href="{{ route('schedules.index') }}">Към разписанията</a>
    </header>
    <section class="heading">
        <div>
            <div class="muted">Администрация · Проверка на възлагания</div>
            <h1>Преподавателско възлагане срещу разписание</h1>
            <p class="muted">Сравнение на планираните часове с поставените блокове в 15-седмичния семестър. Пулът не се брои за изпълнено занятие.</p>
        </div>
    </section>
    <section class="card">
        <form class="filters" method="GET" action="{{ route('catalog.coverage-report') }}">
            <label>Учебен план
                <select name="curriculum_version_id">
                    <option value="">Всички планове</option>
                    @foreach($curriculumVersions as $version)
                        <option value="{{ $version->id }}" @selected($curriculumVersionId === $version->id)>{{ $version->specialty->name }} · {{ $version->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Курс / група
                <select name="student_group_id">
                    <option value="">Всички групи</option>
                    @foreach($groups as $group)
                        <option value="{{ $group->id }}" @selected($studentGroupId === $group->id)>{{ $group->specialty->name }} · {{ $group->course_year }} курс · {{ $group->group_name ?: 'без група' }}</option>
                    @endforeach
                </select>
            </label>
            <label>Учебна година<input name="academic_year" value="{{ $academicYear }}" placeholder="2026/2027"></label>
            <label>Семестър
                <select name="semester_type">
                    <option value="winter" @selected($semesterType === 'winter')>Зимен</option>
                    <option value="summer" @selected($semesterType === 'summer')>Летен</option>
                </select>
            </label>
            <button type="submit">Покажи сверка</button>
        </form>
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Преподавател</th><th>Дисциплина / дейност</th><th>Курс и група</th><th>Планирани</th><th>Поставени</th><th>Сверка</th></tr>
                </thead>
                <tbody>
                @forelse($rows as $row)
                    @php
                        $assignment = $row['assignment'];
                        $groupNames = $assignment->studentFlow?->groups->map(fn ($group) => $group->course_year.' курс · '.$group->display_name)->implode(', ');
                        $scope = $assignment->studentGroup?->display_name
                            ?? ($groupNames ?: ($assignment->curriculumVersion?->specialty?->name
                                ? $assignment->curriculumVersion->specialty->name.' · '.$assignment->course_year.' курс'
                                : 'Общо възлагане'));
                        $teacherName = trim(($assignment->teacher?->title ? $assignment->teacher->title.' ' : '')
                            .($assignment->teacher?->first_name ?? '').' '.($assignment->teacher?->last_name ?? ''));
                    @endphp
                    <tr>
                        <td>{{ $teacherName ?: '—' }}<br><span class="muted">{{ $assignment->employment_category === 'honorary' ? 'Хонорован' : 'Щатен / друг' }}</span></td>
                        <td>{{ $assignment->subject?->name ?? '—' }}<br><span class="muted">{{ $assignment->coverage_type }}{{ $assignment->studentFlow ? ' · '.$assignment->studentFlow->name : '' }}</span></td>
                        <td>{{ $scope }}</td>
                        <td>{{ $row['planned_hours'] }} ч.</td>
                        <td>{{ $row['scheduled_hours'] }} ч.<br><span class="muted">{{ $row['sessions'] }} поставени блока</span></td>
                        <td>
                            @if($row['remaining_hours'] > 0)
                                <span class="status warn">Остават {{ $row['remaining_hours'] }} ч.</span>
                            @elseif($row['excess_hours'] > 0)
                                <span class="status warn">Над плана {{ $row['excess_hours'] }} ч.</span>
                            @else
                                <span class="status">Покрито</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="empty" colspan="6">Няма преподавателски възлагания за избрания план, семестър и филтри.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
