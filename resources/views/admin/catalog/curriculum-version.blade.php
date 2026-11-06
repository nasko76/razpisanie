<!doctype html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $curriculumVersion->name }} · Учебен план</title>
    <style>
        :root { --ink:#17231d; --muted:#6b7771; --line:#e3e9e5; --paper:#fff; --canvas:#f3f6f3; --green:#176b4a; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--canvas); color:var(--ink); font:14px/1.45 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        a { color:inherit; text-decoration:none; }
        .shell { width:100%; max-width:1920px; margin:auto; padding:24px clamp(14px,3vw,48px) 48px; }
        .topbar,.heading { display:flex; align-items:center; justify-content:space-between; gap:16px; }
        .topbar { margin-bottom:30px; color:var(--muted); font-size:13px; }
        .brand { display:flex; align-items:center; gap:10px; color:var(--ink); font-weight:750; }
        .mark { display:grid; width:38px; height:38px; place-items:center; border-radius:12px; background:var(--green); color:#fff; font:20px Georgia,serif; }
        .heading { align-items:end; margin-bottom:20px; }
        .eyebrow { color:var(--green); font-size:10px; font-weight:800; letter-spacing:.13em; text-transform:uppercase; }
        h1 { margin:5px 0; font-size:clamp(25px,3vw,34px); letter-spacing:-.04em; }
        h2 { margin:0; font-size:16px; }
        .muted { color:var(--muted); font-size:12px; }
        .card { overflow:hidden; border:1px solid var(--line); border-radius:14px; background:var(--paper); }
        .card-head { padding:17px 19px; border-bottom:1px solid var(--line); }
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; }
        th,td { padding:12px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
        th { color:var(--muted); font-size:10px; text-transform:uppercase; letter-spacing:.06em; }
        td { font-size:12px; }
        .hours { white-space:nowrap; }
        .teacher { display:block; margin:2px 0; }
        .tag { display:inline-block; padding:3px 7px; border-radius:20px; background:#e8f4ed; color:var(--green); font-size:10px; font-weight:700; }
        .empty { color:var(--muted); }
        .button { display:inline-flex; min-height:38px; align-items:center; padding:0 12px; border:1px solid var(--line); border-radius:9px; background:white; font-weight:650; }
        details { margin-top:8px; }
        summary { display:inline-flex; min-height:38px; align-items:center; padding:0 12px; border:1px solid var(--line); border-radius:9px; background:white; color:var(--green); font-weight:650; cursor:pointer; }
        summary::-webkit-details-marker { display:none; }
        details[open]>summary { margin-bottom:8px; background:#f6fbf7; }
        .record-actions { display:flex; flex-wrap:wrap; align-items:center; gap:7px; }
        .forms { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; padding:16px 19px; border-bottom:1px solid var(--line); background:#fbfcfb; }
        label { display:block; color:var(--muted); font-size:11px; font-weight:700; }
        input,select { width:100%; min-height:37px; margin-top:5px; padding:0 9px; border:1px solid var(--line); border-radius:8px; background:#fff; }
        .span { grid-column:1/-1; }
        .status { margin:12px 0; padding:10px 12px; border-radius:9px; background:#edf8f0; color:#20583c; }
        .row-actions { display:grid; grid-template-columns:1fr 1fr auto; gap:7px; min-width:250px; }
        .row-actions input,.row-actions select { min-width:80px; }
        @media(max-width:850px) { .forms { grid-template-columns:repeat(2,minmax(0,1fr)); } }
        @media(max-width:700px) { .heading { align-items:start; flex-direction:column; } .row-actions { grid-template-columns:1fr; min-width:140px; } }
    </style>
</head>
<body>
<main class="shell">
    <header class="topbar">
        <a class="brand" href="{{ route('catalog.index') }}"><span class="mark">Ф</span><span>ФИИ · Учебен каталог</span></a>
        <a href="{{ route('home') }}">Публично разписание</a>
    </header>
    <section class="heading">
        <div>
            <div class="eyebrow">Обобщен учебен план</div>
            <h1>{{ $curriculumVersion->specialty?->name }} · {{ $curriculumVersion->name }}</h1>
            <p class="muted">Версия {{ $curriculumVersion->version_year }} · {{ $curriculumSubjects->count() }} дисциплини</p>
        </div>
        <div>
            <details>
                <summary>Действия</summary>
                <div class="record-actions">
                    <form method="POST" action="{{ route('catalog.curriculum-versions.duplicate', $curriculumVersion) }}">
                        @csrf
                        <button class="button" type="submit">Копирай план</button>
                    </form>
                    <form method="POST" action="{{ route('catalog.curriculum-versions.update', $curriculumVersion) }}">
                        @csrf @method('PATCH')
                        <button class="button" name="is_active" value="{{ $curriculumVersion->is_active ? '0' : '1' }}">{{ $curriculumVersion->is_active ? 'Архивирай' : 'Активирай' }}</button>
                    </form>
                    <form method="POST" action="{{ route('catalog.curriculum-versions.destroy', $curriculumVersion) }}" onsubmit="return confirm('Изтриването премахва плана и дисциплините в него. Курсовете ще останат без план. Продължаване?')">
                        @csrf @method('DELETE')
                        <button class="button" type="submit" style="color:#a83232">Изтрий версията</button>
                    </form>
                    <a class="button" href="{{ route('catalog.index', ['tab' => 'plans']) }}">Назад към плановете</a>
                </div>
            </details>
        </div>
    </section>

    @if(session('status'))<div class="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="status">{{ $errors->first() }}</div>@endif

    <section class="card">
        <header class="card-head">
            <h2>Редакция на версията</h2>
            <form class="forms" method="POST" action="{{ route('catalog.curriculum-versions.details.update', $curriculumVersion) }}">
                @csrf @method('PATCH')
                <label>Наименование<input name="name" value="{{ $curriculumVersion->name }}" required maxlength="255"></label>
                <label>Година<input name="version_year" type="number" min="2000" max="2200" value="{{ $curriculumVersion->version_year }}" required></label>
                <label>В сила от<input name="active_from" type="date" value="{{ substr((string) $curriculumVersion->active_from, 0, 10) }}"></label>
                <label>В сила до<input name="active_to" type="date" value="{{ substr((string) $curriculumVersion->active_to, 0, 10) }}"></label>
                <button class="button span" type="submit">Запази данните на плана</button>
            </form>
            <h2>Дисциплини, аудиторна заетост и преподаватели</h2>
        </header>
        <form class="forms" method="POST" action="{{ route('catalog.curriculum-subjects.store', $curriculumVersion) }}">
            @csrf
            <label class="span">Добави дисциплина<select name="subject_id" required><option value="">Изберете дисциплина</option>@foreach($subjects as $subject)<option value="{{ $subject->id }}">{{ $subject->code }} · {{ $subject->name }}</option>@endforeach</select></label>
            <label>Курс<input name="course_year" type="number" min="1" max="6" value="1" required></label>
            <label>Семестър<input name="semester_no" type="number" min="1" max="12" value="1" required></label>
            <button class="button span" type="submit">Добави към плана</button>
        </form>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Курс / семестър</th><th>Дисциплина</th><th>Аудиторна заетост</th><th>Самоподготовка</th><th>Общо часове</th><th>Форма на контрол</th><th>Преподавателска обезпеченост</th><th>Редакция</th></tr></thead>
                <tbody>
                @forelse($curriculumSubjects as $curriculumSubject)
                    @php
                        $semester = $curriculumSubject->semester_no % 2 === 1 ? 'winter' : 'summer';
                        $subjectAssignments = $assignments->filter(function ($assignment) use ($curriculumSubject, $curriculumVersion, $semester): bool {
                            if (
                                $assignment->subject_id !== $curriculumSubject->subject_id
                                || ! in_array($assignment->semester, [null, $semester], true)
                            ) {
                                return false;
                            }

                            if (
                                $assignment->curriculum_version_id === $curriculumVersion->id
                                && $assignment->course_year === $curriculumSubject->course_year
                            ) {
                                return true;
                            }

                            return $assignment->studentFlow?->groups->contains(
                                fn ($group): bool => $group->curriculum_version_id === $curriculumVersion->id
                                    && $group->course_year === $curriculumSubject->course_year,
                            ) ?? false;
                        });
                        $auditoriumHours = (int) ($curriculumSubject->subject?->auditorium_hours ?? 0);
                        $selfStudyHours = (int) ($curriculumSubject->subject?->self_study_hours ?? 0);
                    @endphp
                    <tr>
                        <td>{{ $curriculumSubject->course_year }} курс<br><span class="muted">{{ $curriculumSubject->semester_no }} семестър</span></td>
                        <td><strong>{{ $curriculumSubject->subject?->name }}</strong><br><span class="muted">{{ $curriculumSubject->subject?->code }}</span></td>
                        <td class="hours">{{ $auditoriumHours }} ч.<br><span class="muted">Л {{ $curriculumSubject->subject?->lecture_hours }} · У {{ $curriculumSubject->subject?->exercise_hours }}</span></td>
                        <td>{{ $selfStudyHours }} ч.</td>
                        <td><strong>{{ $auditoriumHours + $selfStudyHours }} ч.</strong></td>
                        <td>{{ [
                            'И' => 'Изпит (И)',
                            'Изпит' => 'Изпит (И)',
                            'ТО' => 'Текущ контрол (ТО)',
                            'Текущ контрол' => 'Текущ контрол (ТО)',
                            'П' => 'Продължаваща дисциплина (П)',
                            'Продължаваща дисциплина' => 'Продължаваща дисциплина (П)',
                        ][$curriculumSubject->subject?->control_type] ?? ($curriculumSubject->subject?->control_type ?: '—') }}
                        @if($curriculumSubject->subject?->control_type === 'П')
                            <br><span class="muted">Оценяването е в следващия семестър</span>
                        @endif
                        </td>
                        <td>
                            @forelse($subjectAssignments as $assignment)
                                <span class="teacher">{{ $assignment->teacher?->title }} {{ $assignment->teacher?->first_name }} {{ $assignment->teacher?->last_name }} <span class="tag">{{ $assignment->coverage_type === 'lecture' ? 'Лекции' : 'Упражнения' }}</span>@if($assignment->studentFlow)<span class="tag">{{ $assignment->studentFlow->name }}</span>@endif</span>
                            @empty
                                <span class="empty">Не е назначен преподавател</span>
                            @endforelse
                        </td>
                        <td>
                            <div class="row-actions">
                                <form method="POST" action="{{ route('catalog.curriculum-subjects.update', $curriculumSubject) }}">
                                    @csrf @method('PATCH')
                                    <label>Курс<input name="course_year" type="number" min="1" max="6" value="{{ $curriculumSubject->course_year }}" required></label>
                                    <label>Семестър<input name="semester_no" type="number" min="1" max="12" value="{{ $curriculumSubject->semester_no }}" required></label>
                                    <button class="button" type="submit">Запази</button>
                                </form>
                                <form method="POST" action="{{ route('catalog.curriculum-subjects.destroy', $curriculumSubject) }}" onsubmit="return confirm('Премахване на дисциплината от плана?')">
                                    @csrf @method('DELETE')
                                    <button class="button" type="submit">Премахни</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty">В тази версия на плана няма добавени дисциплини.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
