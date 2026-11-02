<!doctype html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Учебни данни · ФИИ</title>
    <style>
        :root { --ink:#17231d; --muted:#6b7771; --line:#e3e9e5; --paper:#fff; --canvas:#f3f6f3; --green:#176b4a; --soft:#e8f4ed; --red:#a64b20; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--canvas); color:var(--ink); font:14px/1.45 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        a { color:inherit; text-decoration:none; }
        button,input,select { font:inherit; }
        .shell { max-width:1420px; margin:auto; padding:24px clamp(14px,3vw,40px) 48px; }
        .topbar,.heading,.section-head,.row-head { display:flex; align-items:center; justify-content:space-between; gap:16px; }
        .topbar { margin-bottom:30px; color:var(--muted); font-size:13px; }
        .brand { display:flex; align-items:center; gap:10px; color:var(--ink); font-weight:750; }
        .mark { display:grid; width:38px; height:38px; place-items:center; border-radius:12px; background:var(--green); color:#fff; font:20px Georgia,serif; }
        .heading { align-items:end; margin-bottom:22px; }
        .eyebrow { color:var(--green); font-size:10px; font-weight:800; letter-spacing:.13em; text-transform:uppercase; }
        h1 { margin:5px 0; font-size:clamp(25px,3vw,34px); letter-spacing:-.04em; }
        h2 { margin:0; font-size:15px; }
        .lede { margin:0; color:var(--muted); }
        .button { display:inline-flex; min-height:38px; align-items:center; justify-content:center; padding:0 12px; border:1px solid var(--line); border-radius:9px; background:#fff; color:var(--ink); font-weight:650; cursor:pointer; }
        .button.primary { border-color:var(--green); background:var(--green); color:#fff; }
        .notice { margin-bottom:14px; padding:12px 14px; border:1px solid #cfe7d6; border-radius:10px; background:#edf8f0; color:#20583c; }
        .errors { border-color:#efc7b5; background:#fff6ef; color:#8d401c; }
        .errors ul { margin:5px 0 0; padding-left:20px; }
        .sections { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:15px; align-items:start; }
        .card { overflow:hidden; border:1px solid var(--line); border-radius:14px; background:var(--paper); box-shadow:0 6px 24px rgba(32,54,41,.035); }
        .card.wide { grid-column:1/-1; }
        .section-head { padding:17px 18px; border-bottom:1px solid var(--line); }
        .section-head p { margin:3px 0 0; color:var(--muted); font-size:11px; }
        .forms { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; padding:16px 18px; background:#fbfcfb; }
        .forms.single { grid-template-columns:1fr; }
        label { display:block; min-width:0; color:var(--muted); font-size:11px; font-weight:700; }
        input,select { display:block; width:100%; min-height:37px; margin-top:5px; padding:0 10px; border:1px solid var(--line); border-radius:8px; background:white; color:var(--ink); }
        .span { grid-column:1/-1; }
        .help { margin-top:4px; color:#87928b; font-size:10px; }
        .list { padding:0 18px 14px; }
        .item { padding:11px 0; border-top:1px solid var(--line); }
        .item:first-child { border-top:0; }
        .row-head strong { font-size:12px; }
        .muted { color:var(--muted); font-size:11px; }
        .pill { display:inline-flex; padding:3px 8px; border-radius:20px; background:var(--soft); color:var(--green); font-size:10px; font-weight:750; }
        .pill.off { background:#f1f2f1; color:#717a74; }
        details { margin-top:8px; }
        summary { width:max-content; color:var(--green); font-size:11px; font-weight:700; cursor:pointer; }
        .edit-form { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; padding-top:9px; }
        .edit-form .button { align-self:end; }
        .empty { padding:12px 0; color:var(--muted); font-size:12px; }
        .subheading { padding:14px 18px 4px; font-size:12px; font-weight:750; }
        @media(max-width:850px) { .sections { grid-template-columns:1fr; } .card.wide { grid-column:auto; } .heading { align-items:start; flex-direction:column; } }
        @media(max-width:520px) { .forms,.edit-form { grid-template-columns:1fr; } .span { grid-column:auto; } .topbar { align-items:flex-start; } }
    </style>
</head>
<body>
    <main class="shell">
        <header class="topbar">
            <a class="brand" href="{{ route('schedules.index') }}"><span class="mark">Ф</span><span>ФИИ · Учебно разписание</span></a>
            <nav><a href="{{ route('schedules.index') }}">Към разписанието</a></nav>
        </header>
        <section class="heading">
            <div>
                <div class="eyebrow">Администрация · Базови данни</div>
                <h1>Учебен каталог</h1>
                <p class="lede">Управлявайте специалности, версии на планове, дисциплини, групи, преподаватели и зали.</p>
            </div>
        </section>

        @if(session('status'))
            <div class="notice">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="notice errors">
                Проверете въведените данни:
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="sections">
            <section class="card">
                <div class="section-head"><div><h2>Специалности</h2><p>Учебни направления и образователни степени</p></div><span class="pill">{{ $specialties->count() }}</span></div>
                <form class="forms" method="POST" action="{{ route('catalog.specialties.store') }}">
                    @csrf
                    <label class="span">Наименование<input name="name" value="{{ old('name') }}" required maxlength="255"></label>
                    <label>Код<input name="code" value="{{ old('code') }}" maxlength="50"></label>
                    <label>ОКС<input name="degree" value="{{ old('degree') }}" placeholder="Бакалавър"></label>
                    <button class="button primary span" type="submit">Добави специалност</button>
                </form>
                <div class="list">
                    @forelse($specialties as $specialty)
                        <article class="item">
                            <div class="row-head"><strong>{{ $specialty->name }}</strong><span class="muted">{{ $specialty->code }} · {{ $specialty->degree }}</span></div>
                            <div class="muted">{{ $specialty->curriculum_versions_count }} версии на учебен план</div>
                            <details><summary>Редакция</summary>
                                <form class="edit-form" method="POST" action="{{ route('catalog.specialties.update', $specialty) }}">
                                    @csrf @method('PATCH')
                                    <label>Наименование<input name="name" value="{{ $specialty->name }}" required></label>
                                    <label>Код<input name="code" value="{{ $specialty->code }}"></label>
                                    <label>ОКС<input name="degree" value="{{ $specialty->degree }}"></label>
                                    <button class="button" type="submit">Запази</button>
                                </form>
                            </details>
                        </article>
                    @empty
                        <div class="empty">Няма добавени специалности.</div>
                    @endforelse
                </div>
            </section>

            <section class="card">
                <div class="section-head"><div><h2>Версии на учебни планове</h2><p>Новият план се добавя като отделна версия</p></div><span class="pill">{{ $curriculumVersions->count() }}</span></div>
                <form class="forms" method="POST" action="{{ route('catalog.curriculum-versions.store') }}">
                    @csrf
                    <label class="span">Специалност<select name="specialty_id" required>@foreach($specialties as $specialty)<option value="{{ $specialty->id }}">{{ $specialty->name }}</option>@endforeach</select></label>
                    <label>Име на версията<input name="name" placeholder="Учебен план 2026" required></label>
                    <label>Година на версията<input name="version_year" type="number" min="2000" max="2200" value="{{ now()->year }}" required></label>
                    <label>В сила от<input name="active_from" type="date"></label>
                    <label>В сила до<input name="active_to" type="date"></label>
                    <label class="span"><input style="display:inline;width:15px;min-height:15px;margin-right:6px" name="is_active" type="checkbox" value="1">Активирай версията</label>
                    <button class="button primary span" type="submit">Създай версия</button>
                </form>
                <div class="list">
                    @forelse($curriculumVersions as $version)
                        <article class="item">
                            <div class="row-head"><strong>{{ $version->specialty->name }} · {{ $version->name }}</strong><span class="pill {{ $version->is_active ? '' : 'off' }}">{{ $version->is_active ? 'Активен' : 'Архив' }}</span></div>
                            <div class="muted">Версия {{ $version->version_year }} · {{ $version->subjects_count }} дисциплини</div>
                            <form method="POST" action="{{ route('catalog.curriculum-versions.update', $version) }}" style="margin-top:7px">
                                @csrf @method('PATCH')
                                <button class="button" name="is_active" value="{{ $version->is_active ? '0' : '1' }}">{{ $version->is_active ? 'Архивирай' : 'Активирай' }}</button>
                            </form>
                        </article>
                    @empty
                        <div class="empty">Няма версии на учебни планове.</div>
                    @endforelse
                </div>
            </section>

            <section class="card">
                <div class="section-head"><div><h2>Групи и випуски</h2><p>Всяка група е свързана с конкретна версия на плана</p></div><span class="pill">{{ $groups->count() }}</span></div>
                <form class="forms" method="POST" action="{{ route('catalog.groups.store') }}">
                    @csrf
                    <label class="span">Специалност<select name="specialty_id" required>@foreach($specialties as $specialty)<option value="{{ $specialty->id }}">{{ $specialty->name }}</option>@endforeach</select></label>
                    <label class="span">Версия на учебния план<select name="curriculum_version_id" required>@foreach($curriculumVersions as $version)<option value="{{ $version->id }}">{{ $version->specialty->name }} · {{ $version->name }}</option>@endforeach</select></label>
                    <label>Курс<input name="course_year" type="number" min="1" max="6" value="1" required></label>
                    <label>Име на групата<input name="group_name" placeholder="А" required></label>
                    <label class="span">Брой студенти<input name="student_count" type="number" min="1" value="20" required></label>
                    <button class="button primary span" type="submit">Добави група</button>
                </form>
                <div class="list">
                    @forelse($groups as $group)
                        <article class="item">
                            <div class="row-head"><strong>{{ $group->specialty->name }} · {{ $group->course_year }} курс · {{ $group->group_name }}</strong><span class="muted">{{ $group->student_count }} студенти</span></div>
                            <div class="muted">План: {{ $group->curriculumVersion?->name ?? 'Няма зададен' }}</div>
                            <details><summary>Редакция</summary>
                                <form class="edit-form" method="POST" action="{{ route('catalog.groups.update', $group) }}">
                                    @csrf @method('PATCH')
                                    <label>Име на групата<input name="group_name" value="{{ $group->group_name }}" required></label>
                                    <label>Студенти<input name="student_count" type="number" min="1" max="500" value="{{ $group->student_count }}" required></label>
                                    <button class="button" type="submit">Запази</button>
                                </form>
                            </details>
                        </article>
                    @empty
                        <div class="empty">Няма добавени групи.</div>
                    @endforelse
                </div>
            </section>

            <section class="card">
                <div class="section-head"><div><h2>Дисциплини</h2><p>Самоподготовката се отчита отделно от контактните занятия</p></div><span class="pill">{{ $subjects->count() }}</span></div>
                <form class="forms" method="POST" action="{{ route('catalog.subjects.store') }}">
                    @csrf
                    <label>Код<input name="code" maxlength="50"></label>
                    <label>Наименование<input name="name" required></label>
                    <label>Тип<select name="type" required><option value="lecture">Лекция</option><option value="exercise">Упражнение</option><option value="atelier">Ателие</option><option value="practice">Практика</option><option value="selfstudy">Самоподготовка</option><option value="stream_lecture">Поточна лекция</option></select></label>
                    <label>Часове по подразбиране<input name="default_hours" type="number" min="0" max="40" value="0" required></label>
                    <button class="button primary span" type="submit">Добави дисциплина</button>
                </form>
                <div class="list">
                    @forelse($subjects as $subject)
                        <article class="item">
                            <div class="row-head"><strong>{{ $subject->name }}</strong><span class="muted">{{ $subject->code }} · {{ $subject->type }}</span></div>
                            <div class="muted">{{ $subject->curriculum_subjects_count }} включвания в планове</div>
                            <details><summary>Редакция</summary>
                                <form class="edit-form" method="POST" action="{{ route('catalog.subjects.update', $subject) }}">
                                    @csrf @method('PATCH')
                                    <label>Код<input name="code" value="{{ $subject->code }}"></label>
                                    <label>Наименование<input name="name" value="{{ $subject->name }}" required></label>
                                    <label>Тип<select name="type">@foreach(['lecture'=>'Лекция','exercise'=>'Упражнение','atelier'=>'Ателие','practice'=>'Практика','selfstudy'=>'Самоподготовка','stream_lecture'=>'Поточна лекция'] as $type => $label)<option value="{{ $type }}" @selected($subject->type === $type)>{{ $label }}</option>@endforeach</select></label>
                                    <label>Часове<input name="default_hours" type="number" min="0" value="{{ $subject->default_hours }}" required></label>
                                    <button class="button" type="submit">Запази</button>
                                </form>
                            </details>
                        </article>
                    @empty
                        <div class="empty">Няма добавени дисциплини.</div>
                    @endforelse
                </div>
            </section>

            <section class="card wide">
                <div class="section-head"><div><h2>Предметно-часова структура</h2><p>Всяко включване пази семестъра, курса, часовете и седмиците от цикъла</p></div><span class="pill">{{ $curriculumSubjects->count() }}</span></div>
                <form class="forms" method="POST" action="{{ route('catalog.curriculum-subjects.store') }}">
                    @csrf
                    <label>Версия<select name="curriculum_version_id" required>@foreach($curriculumVersions as $version)<option value="{{ $version->id }}">{{ $version->specialty->name }} · {{ $version->name }}</option>@endforeach</select></label>
                    <label>Дисциплина<select name="subject_id" required>@foreach($subjects as $subject)<option value="{{ $subject->id }}">{{ $subject->name }} ({{ $subject->type }})</option>@endforeach</select></label>
                    <label>Курс<input name="course_year" type="number" min="1" max="6" value="1" required></label>
                    <label>Семестър №<input name="semester_no" type="number" min="1" max="12" value="1" required></label>
                    <label>Контактни часове<input name="contact_hours" type="number" min="0" value="0" required></label>
                    <label>Самоподготовка<input name="self_study_hours" type="number" min="0" value="0" required></label>
                    <label>Зададени часове<input name="scheduled_hours" type="number" min="0" value="3" required></label>
                    <label>Контрол<input name="control_type" placeholder="Изпит / проект"></label>
                    <label>Седмици от цикъла<input name="cycle_weeks" value="1,2,3" pattern="[1-3](,[1-3])*" required><span class="help">Въведете 1, 2, 3 или комбинация, например 1,3.</span></label>
                    <button class="button primary" type="submit">Добави в учебен план</button>
                </form>
                <div class="list">
                    @forelse($curriculumSubjects as $item)
                        <article class="item row-head">
                            <div><strong>{{ $item->curriculumVersion->specialty->name }} · {{ $item->subject->name }}</strong><div class="muted">{{ $item->course_year }} курс · семестър {{ $item->semester_no }} · план {{ $item->curriculumVersion->name }}</div></div>
                            <span class="muted">{{ $item->contact_hours }} контактни · {{ $item->self_study_hours }} самоподготовка · седмици {{ implode(', ', $item->cycle_weeks ?? [1,2,3]) }}</span>
                        </article>
                    @empty
                        <div class="empty">Няма дисциплини в учебните планове.</div>
                    @endforelse
                </div>
            </section>

            <section class="card">
                <div class="section-head"><div><h2>Преподаватели</h2><p>Дневна/седмична натовареност и предпочитани дни</p></div><span class="pill">{{ $teachers->count() }}</span></div>
                <form class="forms" method="POST" action="{{ route('catalog.teachers.store') }}">
                    @csrf
                    <label>Име<input name="first_name" required></label>
                    <label>Фамилия<input name="last_name" required></label>
                    <label class="span">Академична длъжност<input name="title" placeholder="доц. д-р"></label>
                    <label>Макс. часа на ден<input name="max_daily_hours" type="number" min="1" max="16" value="8" required></label>
                    <label>Натовареност / седмица<input name="weekly_workload_hours" type="number" min="0" max="80" value="0" required></label>
                    <label>Предпочитани дни<input name="preferred_weekdays" placeholder="1,3"></label>
                    <label>Забранени дни<input name="unavailable_weekdays" placeholder="5"></label>
                    <label class="span">Недостъпни периоди<input name="unavailable_periods" placeholder="1/2/08:00-10:00;3/5/13:00-15:00"><span class="help">Формат: седмица/ден/начало-край; дните са 1–5.</span></label>
                    <button class="button primary span" type="submit">Добави преподавател</button>
                </form>
                <div class="list">
                    @forelse($teachers as $teacher)
                        @php
                            $teacherPeriods = collect($teacher->unavailable_periods ?? [])->map(fn ($period) => $period['cycle_week'].'/'.$period['weekday_no'].'/'.substr($period['start_time'], 0, 5).'-'.substr($period['end_time'], 0, 5))->implode(';');
                        @endphp
                        <article class="item">
                            <div class="row-head"><strong>{{ $teacher->title }} {{ $teacher->first_name }} {{ $teacher->last_name }}</strong><span class="pill {{ $teacher->active ? '' : 'off' }}">{{ $teacher->active ? 'Активен' : 'Неактивен' }}</span></div>
                            <div class="muted">До {{ $teacher->max_daily_hours }} ч./ден · {{ $teacher->weekly_workload_hours }} ч./седмица · {{ $teacher->assignments_count }} дисциплини</div>
                            <details><summary>Редакция</summary>
                                <form class="edit-form" method="POST" action="{{ route('catalog.teachers.update', $teacher) }}">
                                    @csrf @method('PATCH')
                                    <label>Име<input name="first_name" value="{{ $teacher->first_name }}" required></label>
                                    <label>Фамилия<input name="last_name" value="{{ $teacher->last_name }}" required></label>
                                    <label>Длъжност<input name="title" value="{{ $teacher->title }}"></label>
                                    <label>Макс. часа на ден<input name="max_daily_hours" type="number" min="1" max="16" value="{{ $teacher->max_daily_hours }}" required></label>
                                    <label>Часа на седмица<input name="weekly_workload_hours" type="number" min="0" max="80" value="{{ $teacher->weekly_workload_hours }}" required></label>
                                    <label>Предпочитани дни<input name="preferred_weekdays" value="{{ implode(',', $teacher->preferred_weekdays ?? []) }}"></label>
                                    <label>Забранени дни<input name="unavailable_weekdays" value="{{ implode(',', $teacher->unavailable_weekdays ?? []) }}"></label>
                                    <label class="span">Недостъпни периоди<input name="unavailable_periods" value="{{ $teacherPeriods }}" placeholder="1/2/08:00-10:00;3/5/13:00-15:00"></label>
                                    <label>Активност<select name="active"><option value="1" @selected($teacher->active)>Активен</option><option value="0" @selected(!$teacher->active)>Неактивен</option></select></label>
                                    <button class="button" type="submit">Запази</button>
                                </form>
                            </details>
                        </article>
                    @empty
                        <div class="empty">Няма добавени преподаватели.</div>
                    @endforelse
                </div>
            </section>

            <section class="card">
                <div class="section-head"><div><h2>Зали и ателиета</h2><p>Типът и капацитетът ограничават автоматичното разпределение</p></div><span class="pill">{{ $rooms->count() }}</span></div>
                <form class="forms" method="POST" action="{{ route('catalog.rooms.store') }}">
                    @csrf
                    <label>Наименование<input name="name" required></label>
                    <label>Тип<select name="room_type"><option value="lecture">Лекционна</option><option value="atelier">Ателие</option><option value="painting">Живопис</option><option value="graphics">Графика</option><option value="computer">Компютърна</option><option value="studio">Студио</option></select></label>
                    <label>Капацитет<input name="capacity" type="number" min="1" value="25" required></label>
                    <label>Оборудване<input name="equipment" placeholder="проектор, стативи"></label>
                    <label class="span">Недостъпни периоди<input name="unavailable_periods" placeholder="1/2/08:00-10:00;3/5/13:00-15:00"><span class="help">Формат: седмица/ден/начало-край; дните са 1–5.</span></label>
                    <button class="button primary span" type="submit">Добави зала</button>
                </form>
                <div class="list">
                    @forelse($rooms as $room)
                        @php
                            $roomPeriods = collect($room->unavailable_periods ?? [])->map(fn ($period) => $period['cycle_week'].'/'.$period['weekday_no'].'/'.substr($period['start_time'], 0, 5).'-'.substr($period['end_time'], 0, 5))->implode(';');
                        @endphp
                        <article class="item">
                            <div class="row-head"><strong>{{ $room->name }}</strong><span class="pill {{ $room->is_active ? '' : 'off' }}">{{ $room->is_active ? 'Активна' : 'Неактивна' }}</span></div>
                            <div class="muted">{{ $room->room_type }} · {{ $room->capacity }} места · {{ implode(', ', $room->equipment ?? []) }}</div>
                            <details><summary>Редакция</summary>
                                <form class="edit-form" method="POST" action="{{ route('catalog.rooms.update', $room) }}">
                                    @csrf @method('PATCH')
                                    <label>Име<input name="name" value="{{ $room->name }}" required></label>
                                    <label>Тип<select name="room_type">@foreach(['lecture','atelier','computer','painting','graphics','studio'] as $type)<option value="{{ $type }}" @selected($room->room_type === $type)>{{ $type }}</option>@endforeach</select></label>
                                    <label>Капацитет<input name="capacity" type="number" min="1" max="1000" value="{{ $room->capacity }}" required></label>
                                    <label>Оборудване<input name="equipment" value="{{ implode(', ', $room->equipment ?? []) }}"></label>
                                    <label class="span">Недостъпни периоди<input name="unavailable_periods" value="{{ $roomPeriods }}" placeholder="1/2/08:00-10:00;3/5/13:00-15:00"></label>
                                    <label>Активност<select name="is_active"><option value="1" @selected($room->is_active)>Активна</option><option value="0" @selected(!$room->is_active)>Неактивна</option></select></label>
                                    <button class="button" type="submit">Запази</button>
                                </form>
                            </details>
                        </article>
                    @empty
                        <div class="empty">Няма добавени зали.</div>
                    @endforelse
                </div>
            </section>

            <section class="card wide">
                <div class="section-head"><div><h2>Преподавателска обезпеченост</h2><p>Свържете преподавател, дисциплина, курс и версия на плана</p></div><span class="pill">{{ $assignments->count() }}</span></div>
                <form class="forms" method="POST" action="{{ route('catalog.teacher-assignments.store') }}">
                    @csrf
                    <label>Преподавател<select name="teacher_id" required>@foreach($teachers as $teacher)<option value="{{ $teacher->id }}">{{ $teacher->title }} {{ $teacher->first_name }} {{ $teacher->last_name }}</option>@endforeach</select></label>
                    <label>Дисциплина<select name="subject_id" required>@foreach($subjects as $subject)<option value="{{ $subject->id }}">{{ $subject->name }}</option>@endforeach</select></label>
                    <label>Версия на плана<select name="curriculum_version_id" required>@foreach($curriculumVersions as $version)<option value="{{ $version->id }}">{{ $version->specialty->name }} · {{ $version->name }}</option>@endforeach</select></label>
                    <label>Курс<input name="course_year" type="number" min="1" max="6" value="1" required></label>
                    <label>Семестър<select name="semester"><option value="">Всеки</option><option value="winter">Зимен</option><option value="summer">Летен</option></select></label>
                    <button class="button primary" type="submit">Свържи преподавател</button>
                </form>
                <div class="list">
                    @forelse($assignments as $assignment)
                        <article class="item row-head">
                            <div><strong>{{ $assignment->subject->name }} · {{ $assignment->teacher->title }} {{ $assignment->teacher->first_name }} {{ $assignment->teacher->last_name }}</strong><div class="muted">{{ $assignment->curriculumVersion->specialty->name }} · {{ $assignment->course_year }} курс · {{ $assignment->semester ?: 'всеки семестър' }}</div></div>
                            <span class="pill">{{ $assignment->curriculumVersion->name }}</span>
                        </article>
                    @empty
                        <div class="empty">Няма въведена преподавателска обезпеченост.</div>
                    @endforelse
                </div>
            </section>
        </div>
    </main>
</body>
</html>
