<!doctype html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Досие на преподавател · ФИИ</title>
    <style>
        :root { --ink:#17231d; --muted:#6b7771; --line:#e3e9e5; --paper:#fff; --canvas:#f3f6f3; --green:#176b4a; --soft:#e8f4ed; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--canvas); color:var(--ink); font:14px/1.45 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        a { color:inherit; text-decoration:none; }
        .shell { width:100%; max-width:1920px; margin:auto; padding:24px clamp(14px,3vw,48px) 48px; }
        .topbar,.heading,.row { display:flex; align-items:center; justify-content:space-between; gap:16px; }
        .topbar { margin-bottom:30px; color:var(--muted); font-size:13px; }
        .brand { display:flex; align-items:center; gap:10px; font-weight:750; }
        .mark { display:grid; width:38px; height:38px; place-items:center; border-radius:12px; background:var(--green); color:#fff; font:20px Georgia,serif; }
        .heading { align-items:end; margin-bottom:22px; }
        .eyebrow { color:var(--green); font-size:10px; font-weight:800; letter-spacing:.13em; text-transform:uppercase; }
        h1 { margin:5px 0; font-size:clamp(25px,3vw,34px); letter-spacing:-.04em; }
        h2 { margin:0; font-size:16px; }
        .muted { color:var(--muted); font-size:12px; }
        .card { overflow:hidden; margin-bottom:16px; border:1px solid var(--line); border-radius:14px; background:var(--paper); box-shadow:0 6px 24px rgba(32,54,41,.035); }
        .card-head { padding:17px 19px; border-bottom:1px solid var(--line); }
        .card-body { padding:18px 19px; }
        .notice { margin-bottom:14px; padding:12px 14px; border:1px solid #cfe7d6; border-radius:10px; background:#edf8f0; color:#20583c; }
        .days { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:8px; }
        .week-grid { margin:0 0 16px; padding:14px; border:1px solid var(--line); border-radius:11px; }
        .week-grid legend { padding:0 7px; color:var(--ink); font-size:12px; font-weight:750; }
        .day { min-width:0; padding:10px; border:1px solid var(--line); border-radius:10px; background:#fafcfb; }
        .day-name { display:block; min-height:32px; font-size:11px; font-weight:750; }
        .state { display:block; margin-top:5px; cursor:pointer; }
        .state input { position:absolute; opacity:0; pointer-events:none; }
        .state span { display:block; padding:7px 5px; border:1px solid var(--line); border-radius:7px; color:var(--muted); font-size:10px; text-align:center; }
        .state input:checked + span { font-weight:750; box-shadow:0 0 0 1px currentColor; }
        .state.preferred input:checked + span { border-color:#70b78e; background:#e6f5eb; color:#176b4a; }
        .state.neutral input:checked + span { border-color:#e4c96e; background:#fff8df; color:#806300; }
        .state.unavailable input:checked + span { border-color:#e69a9a; background:#fff0ef; color:#a83232; }
        .button { display:inline-flex; min-height:38px; align-items:center; justify-content:center; padding:0 12px; border:1px solid var(--line); border-radius:9px; background:#fff; color:var(--ink); font-weight:650; cursor:pointer; }
        .button.primary { border-color:var(--green); background:var(--green); color:#fff; }
        .button.danger { color:#a83232; }
        label { display:block; margin-bottom:5px; color:var(--muted); font-size:11px; font-weight:700; }
        select { width:100%; min-height:38px; padding:0 9px; border:1px solid var(--line); border-radius:8px; background:#fff; color:var(--ink); }
        .assign-form { display:grid; grid-template-columns:minmax(0,2fr) minmax(140px,1fr) minmax(180px,1fr) auto; align-items:end; gap:10px; }
        .assign-form label { margin:0; }
        .edit-form { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
        .edit-form label { margin:0; }
        .edit-form .span { grid-column:1/-1; }
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; }
        th,td { padding:11px 12px; border-bottom:1px solid var(--line); text-align:left; vertical-align:middle; }
        th { color:var(--muted); font-size:10px; text-transform:uppercase; letter-spacing:.06em; }
        td { font-size:12px; }
        .empty { padding:14px 0; color:var(--muted); }
        .form-errors { margin-bottom:14px; color:#a83232; font-size:12px; }
        @media(max-width:850px) { .days { grid-template-columns:repeat(4,minmax(0,1fr)); } .assign-form { grid-template-columns:1fr 1fr; } .assign-form .subject-select { grid-column:1/-1; } }
        @media(max-width:500px) { .heading { align-items:start; flex-direction:column; } .days { grid-template-columns:repeat(2,minmax(0,1fr)); } .assign-form,.edit-form { grid-template-columns:1fr; } .assign-form .subject-select,.edit-form .span { grid-column:auto; } }
    </style>
</head>
<body>
<main class="shell">
    <header class="topbar">
        <a class="brand" href="{{ route('catalog.index') }}"><span class="mark">Ф</span><span>ФИИ · Учебен каталог</span></a>
        <a href="{{ route('home') }}">Публично разписание</a>
    </header>

    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    @if($errors->any())
        <div class="notice form-errors">Проверете въведените данни: {{ $errors->first() }}</div>
    @endif

    <section class="heading">
        <div>
            <div class="eyebrow">Досие на преподавател</div>
            <h1>{{ $teacher->title }} {{ $teacher->first_name }} {{ $teacher->last_name }}</h1>
            <p class="muted">{{ $teacher->active ? 'Активен преподавател' : 'Неактивен преподавател' }} · {{ $assignments->count() }} дисциплини в обезпечеността</p>
        </div>
        <a class="button" href="{{ route('catalog.index') }}">Назад към каталога</a>
    </section>

    <section class="card">
        <header class="card-head"><h2>Основни данни</h2><p class="muted">Редактирайте натоварването и активността на преподавателя.</p></header>
        <form class="card-body edit-form" method="POST" action="{{ route('catalog.teachers.update', $teacher) }}">
            @csrf @method('PATCH')
            <label>Име<input name="first_name" value="{{ $teacher->first_name }}" required maxlength="100"></label>
            <label>Фамилия<input name="last_name" value="{{ $teacher->last_name }}" required maxlength="100"></label>
            <label class="span">Академична длъжност<input name="title" value="{{ $teacher->title }}" maxlength="100"></label>
            <label>Максимум часове на ден<input name="max_daily_hours" type="number" min="1" max="16" value="{{ $teacher->max_daily_hours }}" required></label>
            <label>Натовареност на седмица<input name="weekly_workload_hours" type="number" min="0" max="80" value="{{ $teacher->weekly_workload_hours }}" required></label>
            <label>Статус<select name="active"><option value="1" @selected($teacher->active)>Активен</option><option value="0" @selected(!$teacher->active)>Неактивен</option></select></label>
            <button class="button primary" type="submit">Запази данните</button>
        </form>
    </section>

    <section class="card">
        <header class="card-head">
            <h2>Предпочитани дни за провеждане на занятия</h2>
            <p class="muted">Настройте отделно за всяка седмица от триседмичния цикъл.</p>
        </header>
        <form class="card-body" method="POST" action="{{ route('catalog.teachers.preferred-days', $teacher) }}">
            @csrf @method('PUT')
            @foreach([1, 2, 3] as $cycleWeek)
                <fieldset class="week-grid">
                    <legend>Седмица {{ $cycleWeek }}</legend>
                    <div class="days">
                        @foreach($days as $weekday => $day)
                            @php($current = $dayPreferences->get($cycleWeek)?->get($weekday) ?? ($weekday >= 6 ? 'unavailable' : 'neutral'))
                            <fieldset class="day">
                                <legend class="day-name">{{ $day }}</legend>
                                @foreach(['preferred' => 'Предпочитам', 'neutral' => 'Без предпоч.', 'unavailable' => 'Не'] as $state => $label)
                                    <label class="state {{ $state }}">
                                        <input type="radio" name="preferences[{{ $cycleWeek }}][{{ $weekday }}]" value="{{ $state }}" @checked($current === $state)>
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </fieldset>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
            <button class="button primary" type="submit" style="margin-top:15px">Запази предпочитанията</button>
        </form>
    </section>

    <section class="card">
        <details>
            <summary style="padding:17px 19px;font-size:16px">Отсъствия по дати · допълнителни настройки</summary>
            <header class="card-head">
                <p class="muted">Посочете диапазон за събития, пътувания или други периоди на недостъпност.</p>
            </header>
            <div class="card-body">
                <form class="edit-form" method="POST" action="{{ route('catalog.teachers.absences.store', $teacher) }}">
                    @csrf
                    <label>От дата<input type="date" name="starts_on" required></label>
                    <label>От час<input type="time" name="starts_time" required></label>
                    <label>До дата<input type="date" name="ends_on" required></label>
                    <label>До час<input type="time" name="ends_time" required></label>
                    <label class="span">Причина<input name="reason" maxlength="255" placeholder="Еразъм, пътуване"></label>
                    <button class="button primary" type="submit">Добави отсъствие</button>
                </form>
                @forelse($teacher->absences as $absence)
                    <form method="POST" action="{{ route('catalog.teachers.absences.destroy', [$teacher, $absence]) }}" style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:12px">
                        @csrf @method('DELETE')
                        <span class="muted">{{ $absence->starts_at->format('d.m.Y H:i') }}–{{ $absence->ends_at->format('d.m.Y H:i') }} · {{ $absence->reason }}</span>
                        <button class="button danger" type="submit">Премахни</button>
                    </form>
                @empty
                    <p class="empty">Няма добавени отсъствия.</p>
                @endforelse
            </div>
        </details>
    </section>

    <section class="card">
        <header class="card-head">
            <h2>Преподавателска обезпеченост</h2>
            <p class="muted">Дисциплини от всички специалности, курсове и версии на планове.</p>
        </header>
        <div class="card-body">
            <form class="assign-form" method="POST" action="{{ route('catalog.teacher-assignments.store') }}" id="teacher-assignment-form">
                @csrf
                <input type="hidden" name="teacher_id" value="{{ $teacher->id }}">
                <input type="hidden" name="subject_id">
                <input type="hidden" name="curriculum_version_id">
                <input type="hidden" name="course_year">
                <input type="hidden" name="semester">
                <label class="subject-select">Дисциплина и учебен план
                    <select id="curriculum-subject" required>
                        <option value="">Изберете дисциплина</option>
                        @foreach($curriculumSubjects as $curriculumSubject)
                            <option value="{{ $curriculumSubject->id }}"
                                data-subject="{{ $curriculumSubject->subject_id }}"
                                data-version="{{ $curriculumSubject->curriculum_version_id }}"
                                data-course="{{ $curriculumSubject->course_year }}"
                                data-semester="{{ $curriculumSubject->semester_no % 2 === 1 ? 'winter' : 'summer' }}">
                                {{ $curriculumSubject->subject?->name }} · {{ $curriculumSubject->curriculumVersion?->specialty?->name }} · {{ $curriculumSubject->course_year }} курс, сем. {{ $curriculumSubject->semester_no }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label>Покритие
                    <select name="coverage_type" required id="coverage-type"><option value="lecture">Лекции</option><option value="exercise">Упражнения</option></select>
                </label>
                <label id="flow-field">Общ поток
                    <select name="student_flow_id">
                        <option value="">Не е обща лекция</option>
                        @foreach($studentFlows as $flow)
                            <option value="{{ $flow->id }}" data-subject="{{ $flow->subject_id }}">{{ $flow->subject?->name }} · {{ $flow->name }} · {{ $flow->groups->count() }} курса</option>
                        @endforeach
                    </select>
                </label>
                <button class="button primary" type="submit">Добави дисциплина</button>
            </form>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Дисциплина</th><th>Специалност и план</th><th>Курс / семестър</th><th>Покритие / поток</th><th></th></tr></thead>
                <tbody>
                    @forelse($assignments as $assignment)
                        <tr>
                            <td>{{ $assignment->subject?->name }}</td>
                            <td>{{ $assignment->curriculumVersion?->specialty?->name ?? $assignment->studentGroup?->specialty?->name }} · {{ $assignment->curriculumVersion?->name ?? 'Без избрана версия на план' }}</td>
                            <td>{{ $assignment->course_year }} курс · {{ $assignment->semester === 'summer' ? 'летен' : 'зимен' }}@if($assignment->studentGroup)<br><span class="muted">Група {{ $assignment->studentGroup->group_name ?: 'обща' }}</span>@endif</td>
                            <td>{{ $assignment->coverage_type === 'lecture' ? 'Лекции' : 'Упражнения' }}@if($assignment->studentFlow)<br><span class="muted">Общ поток: {{ $assignment->studentFlow->name }}</span>@endif @if($assignment->planned_hours !== null || $assignment->academic_year)<br><span class="muted">{{ $assignment->planned_hours ?? '—' }} ч. · {{ $assignment->academic_year }} · {{ $assignment->employment_category === 'honorary' ? 'хоноруван' : ($assignment->employment_category === 'full_time' ? 'щатен' : 'ръчен запис') }}</span>@endif</td>
                            <td>
                                <form method="POST" action="{{ route('catalog.teacher-assignments.destroy', $assignment) }}" onsubmit="return confirm('Премахване на назначението?')">
                                    @csrf @method('DELETE')
                                    <button class="button danger" type="submit">Премахни</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty">Няма добавени дисциплини за този преподавател.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</main>
<script>
    const subjectSelect = document.getElementById('curriculum-subject');
    subjectSelect.addEventListener('change', () => {
        const option = subjectSelect.selectedOptions[0];
        const form = document.getElementById('teacher-assignment-form');
        form.elements.subject_id.value = option.dataset.subject ?? '';
        form.elements.curriculum_version_id.value = option.dataset.version ?? '';
        form.elements.course_year.value = option.dataset.course ?? '';
        form.elements.semester.value = option.dataset.semester ?? '';
    });
    const coverageType = document.getElementById('coverage-type');
    const flowField = document.getElementById('flow-field');
    const flowSelect = flowField.querySelector('select');
    const updateFlowSubjectOptions = () => {
        const selectedSubject = subjectSelect.selectedOptions[0]?.dataset.subject ?? '';
        for (const option of flowSelect.options) {
            if (option.value !== '') {
                option.hidden = option.dataset.subject !== selectedSubject;
                if (option.hidden && option.selected) {
                    flowSelect.value = '';
                }
            }
        }
    };
    subjectSelect.addEventListener('change', updateFlowSubjectOptions);
    const updateFlowAvailability = () => {
        const isLecture = coverageType.value === 'lecture';
        flowSelect.disabled = !isLecture;
        if (!isLecture) {
            flowSelect.value = '';
        }
    };
    coverageType.addEventListener('change', updateFlowAvailability);
    updateFlowSubjectOptions();
    updateFlowAvailability();
</script>
</body>
</html>
