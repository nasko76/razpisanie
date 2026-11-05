<!doctype html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Разписание · ФИИ</title>
    <style>
        :root { color-scheme: light; --ink: #17231d; --muted: #6b7771; --line: #e3e9e5; --paper: #fff; --canvas: #f3f6f3; --green: #176b4a; --green-soft: #e8f4ed; --orange: #a64b20; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--canvas); color: var(--ink); font: 14px/1.45 Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        a { color: inherit; text-decoration: none; }
        button, input, select { font: inherit; }
        .shell { max-width: 1540px; margin: auto; padding: 28px clamp(16px, 3vw, 42px) 54px; }
        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-bottom: 30px; }
        .brand { display: flex; align-items: center; gap: 12px; font-size: 15px; font-weight: 750; letter-spacing: -.02em; }
        .mark { display: grid; width: 40px; height: 40px; place-items: center; border-radius: 13px; background: var(--green); color: white; font-family: Georgia, serif; font-size: 20px; }
        .toplinks { display: flex; align-items: center; gap: 16px; color: var(--muted); font-size: 13px; }
        .toplinks form { margin: 0; }
        .toplinks button { border: 0; background: transparent; color: inherit; cursor: pointer; }
        .eyebrow { color: var(--green); text-transform: uppercase; letter-spacing: .13em; font-size: 10px; font-weight: 800; }
        h1 { margin: 6px 0 7px; font-size: clamp(26px, 3vw, 36px); letter-spacing: -.045em; line-height: 1.1; }
        .lede { color: var(--muted); margin: 0; }
        .heading { display: flex; align-items: end; justify-content: space-between; gap: 20px; margin-bottom: 24px; }
        .actions { display: flex; flex-wrap: wrap; gap: 9px; }
        .button { display: inline-flex; min-height: 40px; align-items: center; justify-content: center; gap: 8px; padding: 0 14px; border: 1px solid var(--line); border-radius: 10px; background: var(--paper); color: var(--ink); font-weight: 650; cursor: pointer; }
        .button.primary { border-color: var(--green); background: var(--green); color: #fff; }
        .button:hover { filter: brightness(.97); }
        .layout { display: grid; grid-template-columns: minmax(230px, 290px) minmax(0, 1fr); align-items: start; gap: 18px; }
        .panel { border: 1px solid var(--line); border-radius: 15px; background: var(--paper); box-shadow: 0 7px 25px rgba(32, 54, 41, .035); }
        .filters { padding: 19px; }
        .panel-title { margin: 0 0 15px; font-size: 14px; font-weight: 750; }
        label { display: block; margin: 13px 0 6px; color: var(--muted); font-size: 12px; font-weight: 650; }
        input, select { width: 100%; min-height: 40px; padding: 0 11px; border: 1px solid var(--line); border-radius: 9px; background: white; color: var(--ink); }
        .filters .button { width: 100%; margin-top: 16px; }
        .meta { margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--line); color: var(--muted); font-size: 12px; }
        .meta strong { display: block; margin: 4px 0; color: var(--ink); font-size: 13px; }
        .schedule { overflow: hidden; }
        .schedule-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px 20px; border-bottom: 1px solid var(--line); }
        .week-caption { color: var(--muted); font-size: 12px; }
        .grid-wrap { overflow-x: auto; padding: 12px; }
        .week-schedule + .week-schedule { margin-top: 16px; }
        .week-title { margin: 4px 10px 0; color: var(--green); font-size: 14px; font-weight: 800; }
        table { width: 100%; min-width: 790px; border-collapse: separate; border-spacing: 6px; table-layout: fixed; }
        th { padding: 7px 9px; color: var(--muted); font-size: 11px; font-weight: 750; text-align: left; text-transform: uppercase; letter-spacing: .07em; }
        th:first-child { width: 154px; }
        td { vertical-align: top; }
        .block-label { min-height: 100px; padding: 13px 10px; border-radius: 10px; background: #f6f8f6; color: var(--muted); font-size: 11px; }
        .block-label strong { display: block; margin-bottom: 5px; color: var(--ink); font-size: 12px; }
        .dropzone { min-height: 100px; padding: 5px; border: 1px dashed #dce5de; border-radius: 10px; background: #fbfcfb; transition: background .12s, border-color .12s; }
        .dropzone.over { border-color: var(--green); background: var(--green-soft); }
        .lesson { margin: 0 0 5px; padding: 9px; border: 1px solid #dfe9e2; border-left: 3px solid var(--green); border-radius: 8px; background: #f3faf5; cursor: grab; }
        .lesson[draggable="false"] { cursor: default; }
        .lesson-title { display: block; font-size: 12px; font-weight: 750; line-height: 1.3; }
        .lesson-meta { display: block; margin-top: 5px; color: var(--muted); font-size: 10px; line-height: 1.45; }
        .lesson-tools { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-top: 7px; }
        summary { color: var(--green); font-size: 10px; font-weight: 750; cursor: pointer; }
        .locked { color: var(--orange); font-size: 10px; font-weight: 750; }
        details { margin-top: 7px; }
        .edit-form { display: grid; gap: 7px; margin-top: 8px; padding-top: 8px; border-top: 1px solid #dfe9e2; }
        .edit-form label { margin: 0; font-size: 10px; }
        .edit-form select { min-height: 32px; padding: 0 7px; font-size: 11px; }
        .edit-form .button { min-height: 31px; padding: 0 8px; font-size: 11px; }
        .check { display: flex; align-items: center; gap: 6px; color: var(--muted); font-size: 10px; }
        .check input { width: 14px; min-height: 14px; }
        .empty-cell { display: grid; min-height: 88px; place-items: center; color: #a2ada6; font-size: 11px; }
        .empty-state { padding: 48px 20px; text-align: center; }
        .empty-state .symbol { font-size: 30px; }
        .empty-state h2 { margin: 8px 0; font-size: 17px; }
        .empty-state p { margin: 0 auto; max-width: 400px; color: var(--muted); }
        .notice { margin: 0 0 15px; padding: 12px 15px; border: 1px solid #cfe7d6; border-radius: 10px; background: #edf8f0; color: #20583c; }
        .notice.warning { border-color: #f2d4ba; background: #fff6ed; color: #8d401c; }
        .notice ul { margin: 8px 0 0; padding-left: 19px; }
        .error { margin-top: 10px; color: #ae3426; font-size: 12px; }
        .mobile-note { display: none; padding: 0 20px 14px; color: var(--muted); font-size: 11px; }
        @media (max-width: 920px) { .layout { grid-template-columns: 1fr; } .filters { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 12px; } .filters .panel-title, .filters .button, .meta { grid-column: 1 / -1; } .heading { align-items: start; flex-direction: column; } }
        @media (max-width: 560px) { .shell { padding: 18px 12px 35px; } .topbar { margin-bottom: 22px; } .toplinks { gap: 8px; } .brand span:last-child { display: none; } .filters { grid-template-columns: 1fr; } .filters .panel-title, .filters .button, .meta { grid-column: auto; } .schedule-head { align-items: start; flex-direction: column; } .mobile-note { display: block; } }
        @media print { body { background: white; } .shell { max-width: none; padding: 0; } .topbar, .heading, .filters, .lesson-tools, .mobile-note { display: none !important; } .layout { display: block; } .panel { border: 0; box-shadow: none; } .schedule-head { padding: 0 0 10px; } .grid-wrap { overflow: visible; padding: 0; } table { min-width: 0; border-spacing: 3px; } .dropzone, .block-label { min-height: 76px; } }
    </style>
</head>
<body>
    <div class="shell">
        <header class="topbar">
            <a class="brand" href="{{ route('schedules.index') }}">
                <span class="mark">Ф</span>
                <span>ФИИ · Учебно разписание</span>
            </a>
            <nav class="toplinks" aria-label="Основна навигация">
                <a href="{{ route('catalog.index') }}">Учебни данни</a>
                <span>{{ auth()->user()->name }}</span>
                <a href="{{ route('profile.edit') }}">Профил</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit">Изход</button>
                </form>
            </nav>
        </header>

        <section class="heading">
            <div>
                <div class="eyebrow">Планиране · 3-седмичен цикъл</div>
                <h1>Разписание</h1>
                <p class="lede">Генерирайте, проверявайте и редактирайте занятията по групи.</p>
            </div>
            @if($selectedGroup)
                <div class="actions">
                    <a class="button" href="{{ route('schedules.history', ['group' => $selectedGroup->id, 'academic_year' => $academicYear, 'semester_type' => $semesterType]) }}">История и сравнение</a>
                    <a class="button" href="{{ route('schedules.export-csv', ['group' => $selectedGroup->id, 'academic_year' => $academicYear, 'semester_type' => $semesterType]) }}">Изтегли CSV</a>
                    <a class="button" href="{{ route('schedules.export-pdf', ['group' => $selectedGroup->id, 'academic_year' => $academicYear, 'semester_type' => $semesterType]) }}">Експорт PDF</a>
                    <button class="button" type="button" onclick="window.print()">Печат</button>
                </div>
            @endif
        </section>

        @if(session('status'))
            <div class="notice">{{ session('status') }}</div>
        @endif
        @if($errors->has('schedule'))
            <div class="notice warning">{{ $errors->first('schedule') }}</div>
        @endif
        @if($scheduleTested)
            <div class="notice {{ count($scheduleTestWarnings) ? 'warning' : '' }}">
                <strong>{{ count($scheduleTestWarnings) ? 'Забележки и препоръки от проверката' : 'Проверката е успешна' }}</strong>
                @if(count($scheduleTestWarnings))
                <ul>
                    @foreach($scheduleTestWarnings as $warning)
                        <li><strong>{{ $warning['subject'] }}</strong> — {{ $warning['reason'] }} {{ $warning['suggestion'] }}</li>
                    @endforeach
                </ul>
                @endif
            </div>
        @endif

        <div class="layout">
            <aside class="panel filters">
                <h2 class="panel-title">Параметри на разписанието</h2>
                <form method="GET" action="{{ route('schedules.index') }}">
                    <label for="group_id">Група</label>
                    <select id="group_id" name="group_id" required>
                        @foreach($groups as $group)
                            <option value="{{ $group->id }}" @selected($selectedGroup?->id === $group->id)>
                                {{ $group->specialty->name }} · {{ $group->course_year }} курс · {{ $group->group_name }}
                            </option>
                        @endforeach
                    </select>

                    <label for="academic_year">Учебна година</label>
                    <input id="academic_year" name="academic_year" value="{{ $academicYear }}" placeholder="2026/2027" required>

                    <label for="semester_type">Семестър</label>
                    <select id="semester_type" name="semester_type">
                        <option value="winter" @selected($semesterType === 'winter')>Зимен</option>
                        <option value="summer" @selected($semesterType === 'summer')>Летен</option>
                    </select>

                    <button class="button primary" type="submit">Покажи разписание</button>
                </form>
                @if($selectedGroup)
                    <div class="meta">
                        Учебен план
                        <strong>{{ $selectedGroup->curriculumVersion?->name ?? 'Няма зададен план' }}</strong>
                        {{ $selectedGroup->student_count }} студенти
                    </div>
                    <details class="meta">
                        <summary>Часове по учебен план · {{ $semesterType === 'winter' ? 'зимен' : 'летен' }} семестър</summary>
                        <ul>
                            @forelse($curriculumSubjects as $curriculumSubject)
                                <li>{{ $curriculumSubject->subject->name }} · АЗ {{ $curriculumSubject->subject->auditorium_hours }} · Л {{ $curriculumSubject->subject->lecture_hours }} · У {{ $curriculumSubject->subject->exercise_hours }} · самоподготовка {{ $curriculumSubject->subject->self_study_hours }}</li>
                            @empty
                                <li>Няма дисциплини за избрания курс и семестър.</li>
                            @endforelse
                        </ul>
                    </details>
                @endif
            </aside>

            <section class="panel schedule" aria-label="Седмично разписание">
                <div class="schedule-head">
                    <div>
                        <h2 class="panel-title" style="margin:0 0 3px">
                            {{ $selectedGroup ? $selectedGroup->specialty->name.' · '.$selectedGroup->group_name : 'Изберете група' }}
                        </h2>
                        <div class="week-caption">{{ $academicYear }} · {{ $semesterType === 'winter' ? 'Зимен семестър' : 'Летен семестър' }} · Седмици 1, 2 и 3</div>
                    </div>
                </div>

                @if($selectedGroup)
                    <div class="mobile-note">Трите седмици са показани едновременно. Плъзнете занятие в друг ден, част от деня или седмица. Натиснете „Редакция“ за преподавател, зала или заключване.</div>
                    @foreach([1, 2, 3] as $week)
                    <section class="week-schedule" aria-label="Разписание за седмица {{ $week }}">
                    <h3 class="week-title">Седмица {{ $week }}</h3>
                    <div class="grid-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Част от деня</th>
                                    @foreach($weekdays as $day)
                                        <th>{{ $day }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($dayParts as $part)
                                    <tr>
                                        <td>
                                            <div class="block-label">
                                                <strong>{{ $part['label'] }}</strong>
                                            </div>
                                        </td>
                                        @foreach($weekdays as $dayNo => $day)
                                            @php
                                                $cellEntries = $entries->filter(function ($entry) use ($dayNo, $part, $week) {
                                                    $start = substr($entry->start_time, 0, 5);
                                                    $after = $part['after'] ?? null;
                                                    return $entry->cycle_week === $week
                                                        && $entry->weekday_no === $dayNo
                                                        && ($after === null || $start >= $after)
                                                        && ($part['before'] === null || $start < $part['before']);
                                                });
                                            @endphp
                                            <td class="dropzone" data-drop-week="{{ $week }}" data-drop-day="{{ $dayNo }}" data-drop-part="{{ $part['key'] }}">
                                                @forelse($cellEntries as $entry)
                                                    @php
                                                        $subjectTeacherNames = $teacherNamesBySubject[$entry->subject_id] ?? [];
                                                        if ($subjectTeacherNames === [] && $entry->teacher) {
                                                            $subjectTeacherNames[] = trim(($entry->teacher->title ? $entry->teacher->title.' ' : '').$entry->teacher->first_name.' '.$entry->teacher->last_name);
                                                        }
                                                    @endphp
                                                    <article class="lesson"
                                                             draggable="{{ $entry->locked ? 'false' : 'true' }}"
                                                             data-entry-id="{{ $entry->id }}">
                                                        <span class="lesson-title">{{ $entry->subject?->name ?? $entry->custom_subject_name }}</span>
                                                        <span class="lesson-meta">{{ substr($entry->start_time, 0, 5) }}–{{ substr($entry->end_time, 0, 5) }} · {{ $entry->duration_hours }} уч. ч. · {{ $entry->room?->name ?? 'Без зала' }}<br>{{ $subjectTeacherNames === [] ? 'Преподавател не е зададен' : implode(', ', $subjectTeacherNames) }}@if($entry->studentFlow)<br>Общ поток: {{ $entry->studentFlow->name }}@endif</span>
                                                        @if($entry->note)<span class="lesson-meta">{{ $entry->note }}</span>@endif
                                                        <div class="lesson-tools">
                                                            @if($entry->locked)
                                                                <span class="locked">Заключено</span>
                                                            @else
                                                                <span class="week-caption">{{ $entry->duration_hours }} ч.</span>
                                                            @endif
                                                            <details>
                                                                <summary>Редакция</summary>
                                                                <form class="edit-form" method="POST" action="{{ route('schedules.update', $entry) }}">
                                                                    @csrf
                                                                    @method('PATCH')
                                                                    <input type="hidden" name="group_id" value="{{ $selectedGroup->id }}">
                                                                    <input type="hidden" name="academic_year" value="{{ $academicYear }}">
                                                                    <input type="hidden" name="semester_type" value="{{ $semesterType }}">
                                                                    <label>Седмица
                                                                        <select name="cycle_week">
                                                                            @foreach([1, 2, 3] as $week)
                                                                                <option value="{{ $week }}" @selected($entry->cycle_week === $week)>{{ $week }}</option>
                                                                            @endforeach
                                                                        </select>
                                                                    </label>
                                                                    <label>Ден
                                                                        <select name="weekday_no">
                                                                            @foreach($weekdays as $dayValue => $dayLabel)
                                                                                <option value="{{ $dayValue }}" @selected($entry->weekday_no === $dayValue)>{{ $dayLabel }}</option>
                                                                            @endforeach
                                                                        </select>
                                                                    </label>
                                                                    <label>Начален час<input type="time" name="start_time" value="{{ substr($entry->start_time, 0, 5) }}" required></label>
                                                                    <label>Краен час<input type="time" name="end_time" value="{{ substr($entry->end_time, 0, 5) }}" required></label>
                                                                    <label>Учебни часове<input type="number" name="duration_hours" min="1" max="16" value="{{ $entry->duration_hours }}" required></label>
                                                                    <label>Свободно име<input name="custom_subject_name" value="{{ $entry->custom_subject_name }}" maxlength="255"></label>
                                                                    <label>Бележка<textarea name="note" rows="2" maxlength="2000">{{ $entry->note }}</textarea></label>
                                                                    <label>Вид занятие
                                                                        <select name="coverage_type">
                                                                            <option value="">Не е уточнен</option>
                                                                            <option value="lecture" @selected($entry->coverage_type === 'lecture')>Лекции</option>
                                                                            <option value="exercise" @selected($entry->coverage_type === 'exercise')>Упражнения</option>
                                                                        </select>
                                                                    </label>
                                                                    <label>Поток
                                                                        <select name="student_flow_id">
                                                                            <option value="">Без общ поток</option>
                                                                            @foreach($studentFlows as $flow)
                                                                                <option value="{{ $flow->id }}" @selected($entry->student_flow_id === $flow->id)>{{ $flow->name }}</option>
                                                                            @endforeach
                                                                        </select>
                                                                    </label>
                                                                    <label>Преподавател
                                                                        <select name="teacher_id">
                                                                            <option value="">Не е зададен</option>
                                                                            @foreach($teachers as $teacher)
                                                                                <option value="{{ $teacher->id }}" @selected($entry->teacher_id === $teacher->id)>{{ $teacher->title ? $teacher->title.' ' : '' }}{{ $teacher->first_name }} {{ $teacher->last_name }}</option>
                                                                            @endforeach
                                                                        </select>
                                                                    </label>
                                                                    <label>Зала
                                                                        <select name="room_id">
                                                                            <option value="">Без зала</option>
                                                                            @foreach($rooms as $room)
                                                                                <option value="{{ $room->id }}" @selected($entry->room_id === $room->id)>{{ $room->name }} · {{ $room->capacity }} места</option>
                                                                            @endforeach
                                                                        </select>
                                                                    </label>
                                                                    <label class="check">
                                                                        <input type="checkbox" name="locked" value="1" @checked($entry->locked)>
                                                                        Заключи блока
                                                                    </label>
                                                                    <button class="button primary" type="submit">Запази</button>
                                                                </form>
                                                                @if(!$entry->locked)
                                                                    <form method="POST" action="{{ route('schedules.destroy', $entry) }}" onsubmit="return confirm('Да се премахне ли занятието?')">
                                                                        @csrf
                                                                        @method('DELETE')
                                                                        <input type="hidden" name="group_id" value="{{ $selectedGroup->id }}">
                                                                        <input type="hidden" name="academic_year" value="{{ $academicYear }}">
                                                                        <input type="hidden" name="semester_type" value="{{ $semesterType }}">
                                                                        <input type="hidden" name="cycle_week" value="{{ $cycleWeek }}">
                                                                        <button class="button" type="submit" style="width:100%;margin-top:6px;color:#a33">Премахни</button>
                                                                    </form>
                                                                @endif
                                                            </details>
                                                        </div>
                                                    </article>
                                                @empty
                                                    <div class="empty-cell">Свободен блок</div>
                                                @endforelse
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    </section>
                    @endforeach

                    <div class="actions" style="padding:0 20px 20px">
                        @if($selectedGroup->curriculum_version_id)
                        <form method="POST" action="{{ route('schedules.generate') }}">
                            @csrf
                            <input type="hidden" name="group_id" value="{{ $selectedGroup->id }}">
                            <input type="hidden" name="academic_year" value="{{ $academicYear }}">
                            <input type="hidden" name="semester_type" value="{{ $semesterType }}">
                            <button class="button primary" type="submit">Генерирай отново</button>
                        </form>
                        @endif
                        <form method="POST" action="{{ route('schedules.test') }}">
                            @csrf
                            <input type="hidden" name="group_id" value="{{ $selectedGroup->id }}">
                            <input type="hidden" name="academic_year" value="{{ $academicYear }}">
                            <input type="hidden" name="semester_type" value="{{ $semesterType }}">
                            <button class="button" type="submit">Тествай разписанието</button>
                        </form>
                        <span class="week-caption">Забележките и препоръките се показват след теста. Заключените занятия се запазват при генериране.</span>
                    </div>
                    @unless($selectedGroup->curriculum_version_id)
                        <p class="week-caption" style="padding:0 20px 20px">Групата няма учебен план. Добавяйте занятията ръчно.</p>
                    @endunless

                    <div style="padding:0 20px 20px">
                        <form method="POST" action="{{ route('schedules.note.update') }}" class="edit-form">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="group_id" value="{{ $selectedGroup->id }}">
                            <input type="hidden" name="academic_year" value="{{ $academicYear }}">
                            <input type="hidden" name="semester_type" value="{{ $semesterType }}">
                            <input type="hidden" name="cycle_week" value="{{ $cycleWeek }}">
                            <label>Бележка към целия график<textarea name="note" rows="2" maxlength="5000">{{ $note }}</textarea></label>
                            <button class="button" type="submit">Запази бележката</button>
                        </form>

                        <details style="margin-top:16px">
                            <summary>Добави свободно занятие или корекция</summary>
                            <form method="POST" action="{{ route('schedules.entries.store') }}" class="edit-form" style="margin-top:10px">
                                @csrf
                                <input type="hidden" name="group_id" value="{{ $selectedGroup->id }}">
                                <input type="hidden" name="academic_year" value="{{ $academicYear }}">
                                <input type="hidden" name="semester_type" value="{{ $semesterType }}">
                                <label>Седмица<select name="cycle_week">@foreach([1, 2, 3] as $week)<option value="{{ $week }}" @selected($cycleWeek === $week)>Седмица {{ $week }}</option>@endforeach</select></label>
                                <label>Наименование<input name="custom_subject_name" maxlength="255" required></label>
                                <label>Ден<select name="weekday_no">@foreach($weekdays as $dayNo => $dayLabel)<option value="{{ $dayNo }}">{{ $dayLabel }}</option>@endforeach</select></label>
                                <label>Начален час<input type="time" name="start_time" value="08:00" required></label>
                                <label>Краен час<input type="time" name="end_time" value="11:45" required></label>
                                <label>Учебни часове<input type="number" name="duration_hours" min="1" max="16" value="4" required></label>
                                <label>Поток<select name="student_flow_id"><option value="">Без общ поток</option>@foreach($studentFlows as $flow)<option value="{{ $flow->id }}">{{ $flow->name }}</option>@endforeach</select></label>
                                <label>Вид занятие<select name="coverage_type"><option value="">Не е уточнен</option><option value="lecture">Лекции</option><option value="exercise">Упражнения</option></select></label>
                                <label>Преподавател<select name="teacher_id"><option value="">Без преподавател</option>@foreach($teachers as $teacher)<option value="{{ $teacher->id }}">{{ $teacher->title }} {{ $teacher->first_name }} {{ $teacher->last_name }}</option>@endforeach</select></label>
                                <label>Зала<select name="room_id"><option value="">Без зала</option>@foreach($rooms as $room)<option value="{{ $room->id }}">{{ $room->name }}</option>@endforeach</select></label>
                                <label class="span">Корекция / бележка<textarea name="note" rows="2" maxlength="2000"></textarea></label>
                                <button class="button primary" type="submit">Добави в графика</button>
                            </form>
                        </details>
                    </div>
                @else
                    <div class="empty-state">
                        <div class="symbol">▦</div>
                        <h2>Все още няма групи</h2>
                        <p>Добавете специалност, версия на учебен план, група, дисциплини и зали, за да започнете планирането.</p>
                        <a class="button primary" href="{{ route('catalog.index') }}" style="margin-top:16px">Отвори учебния каталог</a>
                    </div>
                @endif
            </section>
        </div>
        <div id="drag-message" class="error" role="status" aria-live="polite"></div>
    </div>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const message = document.getElementById('drag-message');
        let draggedEntry = null;

        document.querySelectorAll('.lesson[draggable="true"]').forEach((lesson) => {
            lesson.addEventListener('dragstart', () => {
                draggedEntry = lesson;
            });
        });

        document.querySelectorAll('.dropzone').forEach((zone) => {
            zone.addEventListener('dragover', (event) => {
                event.preventDefault();
                zone.classList.add('over');
            });
            zone.addEventListener('dragleave', () => zone.classList.remove('over'));
            zone.addEventListener('drop', async (event) => {
                event.preventDefault();
                zone.classList.remove('over');
                if (!draggedEntry) return;

                const form = draggedEntry.querySelector('.edit-form');
                const targetStartMinutes = { morning: 8 * 60, afternoon: 13 * 60, evening: 16 * 60 }[zone.dataset.dropPart];
                const [currentStartHour, currentStartMinute] = form.elements.start_time.value.split(':').map(Number);
                const [currentEndHour, currentEndMinute] = form.elements.end_time.value.split(':').map(Number);
                const elapsedMinutes = (currentEndHour * 60 + currentEndMinute) - (currentStartHour * 60 + currentStartMinute);
                const targetEndMinutes = targetStartMinutes + elapsedMinutes;
                if (targetEndMinutes >= 24 * 60) {
                    message.textContent = 'Избраният часови диапазон не се побира в деня.';
                    return;
                }
                const formatTime = (minutes) => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
                const data = new URLSearchParams({
                    cycle_week: zone.dataset.dropWeek,
                    weekday_no: zone.dataset.dropDay,
                    start_time: formatTime(targetStartMinutes),
                    end_time: formatTime(targetEndMinutes),
                    duration_hours: form.elements.duration_hours.value,
                    coverage_type: form.elements.coverage_type.value,
                    student_flow_id: form.elements.student_flow_id.value,
                    teacher_id: form.elements.teacher_id.value,
                    room_id: form.elements.room_id.value,
                    locked: form.elements.locked.checked ? '1' : '0',
                });
                const response = await fetch(form.action, {
                    method: 'PATCH',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: data,
                });

                if (response.ok) {
                    window.location.reload();
                    return;
                }

                const result = await response.json();
                message.textContent = Object.values(result.errors ?? {}).flat().join(' ');
            });
        });
    </script>
</body>
</html>
