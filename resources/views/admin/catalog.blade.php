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
        .shell { width:100%; max-width:1920px; margin:auto; padding:24px clamp(14px,3vw,48px) 48px; }
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
        .sections>.card:only-child { grid-column:1/-1; }
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
        .list.three-columns { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; }
        .list.three-columns>.item { min-width:0; padding:12px; border:1px solid var(--line); border-radius:10px; }
        .item { padding:11px 0; border-top:1px solid var(--line); }
        .item:first-child { border-top:0; }
        .row-head strong { font-size:12px; }
        .muted { color:var(--muted); font-size:11px; }
        .pill { display:inline-flex; padding:3px 8px; border-radius:20px; background:var(--soft); color:var(--green); font-size:10px; font-weight:750; }
        .pill.off { background:#f1f2f1; color:#717a74; }
        details { margin-top:8px; }
        summary { display:inline-flex; min-height:34px; align-items:center; width:max-content; padding:0 10px; border:1px solid var(--line); border-radius:8px; background:#fff; color:var(--green); font-size:11px; font-weight:700; cursor:pointer; }
        summary::-webkit-details-marker { display:none; }
        details[open]>summary { margin-bottom:8px; border-color:#b9d8c6; background:#f6fbf7; }
        .record-editor { margin:0; }
        .record-editor>.record-summary { display:block; width:100%; min-height:0; padding:12px; border:0; border-radius:inherit; background:transparent; color:inherit; font-size:inherit; }
        .record-editor[open]>.record-summary { margin:0; border:0; background:#f6fbf7; }
        .record-editor>.record-summary:hover { background:#f6f9f6; }
        .record-editor>.record-summary:focus-visible { outline:2px solid var(--green); outline-offset:-2px; }
        .record-editor>.record-summary::after { content:""; float:right; width:8px; height:8px; margin:4px 3px 0 12px; border-right:1.5px solid var(--muted); border-bottom:1.5px solid var(--muted); transform:rotate(45deg); transition:transform .15s ease; }
        .record-editor[open]>.record-summary::after { transform:rotate(225deg); }
        .record-editor>.record-summary .row-head { margin:0; }
        .record-editor>.record-summary>.muted { display:block; margin-top:4px; }
        .record-editor>.editor-panel { padding:10px 12px 12px; border-top:1px solid var(--line); }
        .record-link { display:block; margin:-11px 0; padding:11px 0; border-radius:8px; }
        .record-link:hover { background:#f6f9f6; }
        .edit-form { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; padding-top:9px; }
        .edit-form .button { align-self:end; }
        .empty { padding:12px 0; color:var(--muted); font-size:12px; }
        .subheading { padding:14px 18px 4px; font-size:12px; font-weight:750; }
        .tabnav { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 16px; }
        .tabnav a { display:inline-flex; min-height:40px; align-items:center; padding:0 14px; border:1px solid var(--line); border-radius:10px; background:#fff; font-weight:700; }
        .tabnav a.active { border-color:var(--green); background:var(--soft); color:var(--green); }
        @media(max-width:1200px) { .list.three-columns { grid-template-columns:repeat(3,minmax(0,1fr)); } }
        @media(max-width:850px) { .sections { grid-template-columns:1fr; } .sections>.card:only-child { grid-column:auto; } .card.wide { grid-column:auto; } .heading { align-items:start; flex-direction:column; } .list.three-columns { grid-template-columns:repeat(2,minmax(0,1fr)); } }
        @media(max-width:520px) { .forms,.edit-form,.list.three-columns { grid-template-columns:1fr; } .span { grid-column:auto; } .topbar { align-items:flex-start; } }
    </style>
</head>
<body>
    <main class="shell">
        <header class="topbar">
            <a class="brand" href="{{ route('schedules.index') }}"><span class="mark">Ф</span><span>ФИИ · Учебно разписание</span></a>
            <nav style="display:flex;gap:14px"><a href="{{ route('schedules.index') }}">Към разписанието</a><a href="{{ route('settings.index') }}">Общи настройки</a></nav>
        </header>
        <section class="heading">
            <div>
                <div class="eyebrow">Администрация · Базови данни</div>
                <h1>Учебен каталог</h1>
                <p class="lede">Управлявайте специалности, версии на планове, дисциплини, групи, преподаватели и зали.</p>
            </div>
            <a class="button primary" href="{{ route('imports.index') }}">Импорт на учебни данни</a>
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

        <nav class="tabnav" aria-label="Секции на учебния каталог">
            @foreach(['plans' => 'Учебни планове', 'subjects' => 'Дисциплини', 'teachers' => 'Преподаватели', 'organization' => 'Курс и групи'] as $tab => $label)
                <a href="{{ route('catalog.index', ['tab' => $tab]) }}" @if($activeTab === $tab) class="active" aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        <div class="sections">
            @if($activeTab === 'plans')
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
                        <details class="item record-editor">
                            <summary class="record-summary">
                                <span class="row-head"><strong>{{ $specialty->name }}</strong><span class="muted">{{ $specialty->code }} · {{ $specialty->degree }}</span></span>
                                <span class="muted">{{ $specialty->curriculum_versions_count }} версии на учебен план</span>
                            </summary>
                            <div class="editor-panel">
                                <form class="edit-form" method="POST" action="{{ route('catalog.specialties.update', $specialty) }}">
                                    @csrf @method('PATCH')
                                    <label>Наименование<input name="name" value="{{ $specialty->name }}" required></label>
                                    <label>Код<input name="code" value="{{ $specialty->code }}"></label>
                                    <label>ОКС<input name="degree" value="{{ $specialty->degree }}"></label>
                                    <button class="button" type="submit">Запази</button>
                                </form>
                                <form method="POST" action="{{ route('catalog.specialties.destroy', $specialty) }}" onsubmit="return confirm('Изтриването ще премахне специалността, плановете и групите към нея. Продължаване?')">
                                    @csrf @method('DELETE')
                                    <button class="button" type="submit" style="color:#a83232">Изтрий специалността</button>
                                </form>
                            </div>
                        </details>
                    @empty
                        <div class="empty">Няма добавени специалности.</div>
                    @endforelse
                </div>
            </section>
            @endif

            @if($activeTab === 'plans')
            <section class="card">
                <div class="section-head"><div><h2>Версии на учебни планове</h2><p>Новият план се добавя като отделна версия</p></div><span class="pill">{{ $curriculumVersions->count() }}</span></div>
                <form class="forms" method="POST" action="{{ route('catalog.curriculum-versions.store') }}">
                    @csrf
                    <label class="span">Специалност<select name="specialty_id" required>@foreach($specialties as $specialty)<option value="{{ $specialty->id }}">{{ $specialty->name }}</option>@endforeach</select></label>
                    <label>Име на версията<input name="name" placeholder="Учебен план 2026" required></label>
                    <label>Година на версията<input name="version_year" type="number" min="2000" max="2200" value="{{ now()->year }}" required></label>
                    <label>Текущ семестър<select name="current_semester">@foreach([1, 2, 3, 4] as $semester)<option value="{{ $semester }}" @selected($semester === 1)>{{ $semester }}</option>@endforeach</select></label>
                    <label>В сила от<input name="active_from" type="date"></label>
                    <label>В сила до<input name="active_to" type="date"></label>
                    <label class="span"><input style="display:inline;width:15px;min-height:15px;margin-right:6px" name="is_active" type="checkbox" value="1">Активирай версията</label>
                    <button class="button primary span" type="submit">Създай версия</button>
                </form>
                <div class="list">
                    @forelse($curriculumVersions as $version)
                        <article class="item">
                            <a class="record-link" href="{{ route('catalog.curriculum-versions.show', $version) }}">
                                <div class="row-head"><strong>{{ $version->specialty->name }} · {{ $version->name }}</strong><span class="pill {{ $version->is_active ? '' : 'off' }}">{{ $version->is_active ? 'Активен' : 'Архив' }}</span></div>
                                <div class="muted">Версия {{ $version->version_year }} · текущ семестър {{ $version->current_semester }} · {{ $version->subjects_count }} дисциплини</div>
                            </a>
                        </article>
                    @empty
                        <div class="empty">Няма версии на учебни планове.</div>
                    @endforelse
                </div>
            </section>
            @endif

            @if($activeTab === 'organization')
            <section class="card">
                <div class="section-head"><div><h2>Курс и групи</h2><p>Курсът може да е без отделни групи; за общи лекции избирайте курсове от различни планове.</p></div><span class="pill">{{ $groups->count() }}</span></div>
                <form class="forms" method="POST" action="{{ route('catalog.groups.store') }}">
                    @csrf
                    <label class="span">Специалност<select name="specialty_id" required>@foreach($specialties as $specialty)<option value="{{ $specialty->id }}">{{ $specialty->name }}</option>@endforeach</select></label>
                    <label class="span">Версия на учебния план<select name="curriculum_version_id"><option value="">Без учебен план (напр. магистри)</option>@foreach($curriculumVersions as $version)<option value="{{ $version->id }}">{{ $version->specialty->name }} · {{ $version->name }}</option>@endforeach</select></label>
                    <label>Курс<input name="course_year" type="number" min="1" max="6" value="1" required></label>
                    <label>Група <span class="help">(попълнете само при разделяне на групи)</span><input name="group_name" placeholder="А"></label>
                    <label class="span">Брой студенти<input name="student_count" type="number" min="1" value="20" required></label>
                    <button class="button primary span" type="submit">Добави група</button>
                </form>
                <div class="list">
                    @forelse($groups as $group)
                        <details class="item record-editor">
                            <summary class="record-summary">
                                <span class="row-head"><strong>{{ $group->specialty->name }} · {{ $group->display_name }}</strong><span class="muted">{{ $group->student_count }} студенти</span></span>
                                <span class="muted">План: {{ $group->curriculumVersion?->name ?? 'Няма зададен' }}</span>
                            </summary>
                            <div class="editor-panel">
                                <form class="edit-form" method="POST" action="{{ route('catalog.groups.update', $group) }}">
                                    @csrf @method('PATCH')
                                    <label>Група <span class="help">(по избор)</span><input name="group_name" value="{{ $group->group_name }}"></label>
                                    <label>Студенти<input name="student_count" type="number" min="1" max="500" value="{{ $group->student_count }}" required></label>
                                    <button class="button" type="submit">Запази</button>
                                </form>
                                <form method="POST" action="{{ route('catalog.groups.destroy', $group) }}" onsubmit="return confirm('Изтриването ще премахне занятията и историята на този курс/група. Продължаване?')">
                                    @csrf @method('DELETE')
                                    <button class="button" type="submit" style="color:#a83232">Изтрий курс/група</button>
                                </form>
                            </div>
                        </details>
                    @empty
                        <div class="empty">Няма добавени групи.</div>
                    @endforelse
                </div>
            </section>
            @endif

            @if($activeTab === 'organization')
            <section class="card">
                <div class="section-head"><div><h2>Потоци по дисциплина</h2><p>Една обща лекционна дисциплина за курсове от различни специалности и учебни планове; часът се споделя при генериране.</p></div><span class="pill">{{ $studentFlows->count() }}</span></div>
                <form class="forms" method="POST" action="{{ route('catalog.student-flows.store') }}">
                    @csrf
                    <label class="span">Име на потока<input name="name" required maxlength="255" placeholder="Поток 1 · Лекции"></label>
                    <label class="span">Обща лекционна дисциплина
                        <select name="subject_id" required>
                            <option value="">Изберете дисциплина</option>
                            @foreach($subjects as $subject)
                                <option value="{{ $subject->id }}">{{ $subject->code }} · {{ $subject->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="span">Групи в потока
                        <select name="student_group_ids[]" multiple size="5" required>
                            @foreach($groups as $group)
                                <option value="{{ $group->id }}">{{ $group->specialty->name }} · {{ $group->display_name }}</option>
                            @endforeach
                        </select>
                        <span class="help">Изберете поне две групи, които посещават общите лекции.</span>
                    </label>
                    <button class="button primary span" type="submit">Създай поток</button>
                </form>
                <div class="list">
                    @forelse($studentFlows as $flow)
                        <details class="item record-editor">
                            <summary class="record-summary">
                                <strong>{{ $flow->subject?->name ?? 'Нужно задаване на дисциплина' }} · {{ $flow->name }}</strong>
                                <span class="muted">{{ $flow->groups->map(fn ($group) => $group->specialty->name.' · '.$group->display_name)->join(', ') }}</span>
                            </summary>
                            <div class="editor-panel">
                                <form class="edit-form" method="POST" action="{{ route('catalog.student-flows.update', $flow) }}">
                                    @csrf @method('PATCH')
                                    <label class="span">Име<input name="name" value="{{ $flow->name }}" required maxlength="255"></label>
                                    <label class="span">Дисциплина
                                        <select name="subject_id" required>
                                            <option value="">Изберете дисциплина</option>
                                            @foreach($subjects as $subject)
                                                <option value="{{ $subject->id }}" @selected($flow->subject_id === $subject->id)>{{ $subject->code }} · {{ $subject->name }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="span">Групи
                                        <select name="student_group_ids[]" multiple size="5" required>
                                            @foreach($groups as $group)
                                                <option value="{{ $group->id }}" @selected($flow->groups->contains('id', $group->id))>{{ $group->specialty->name }} · {{ $group->display_name }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <button class="button" type="submit">Запази потока</button>
                                </form>
                                <form method="POST" action="{{ route('catalog.student-flows.destroy', $flow) }}" onsubmit="return confirm('Изтриването прекъсва синхронизацията на общата лекция. Продължаване?')">
                                    @csrf @method('DELETE')
                                    <button class="button" type="submit" style="color:#a83232">Изтрий потока</button>
                                </form>
                            </div>
                        </details>
                    @empty
                        <div class="empty">Няма добавени потоци.</div>
                    @endforelse
                </div>
            </section>
            @endif

            @if($activeTab === 'subjects')
            <section class="card">
                <div class="section-head"><div><h2>Дисциплини</h2><p>АЗ, лекции, упражнения, самоподготовка и форма на контрол</p></div><span class="pill">{{ $subjects->count() }}</span></div>
                <form class="forms" method="POST" action="{{ route('catalog.subjects.store') }}">
                    @csrf
                    <label>Код<input name="code" maxlength="50"></label>
                    <label>Наименование<input name="name" required></label>
                    <label>АЗ · аудиторна заетост<input name="auditorium_hours" type="number" min="0" max="1000" value="0" required></label>
                    <label>Л · лекции<input name="lecture_hours" type="number" min="0" max="1000" value="0" required></label>
                    <label>У · упражнения<input name="exercise_hours" type="number" min="0" max="1000" value="0" required></label>
                    <label>Самоподготовка<input name="self_study_hours" type="number" min="0" max="1000" value="0" required></label>
                    <label>Форма на контрол<select name="control_type"><option value="">Не е зададена</option><option value="И">Изпит (И)</option><option value="ТО">Текущ контрол (ТО)</option><option value="П">Продължаваща дисциплина (П)</option></select></label>
                    <button class="button primary span" type="submit">Добави дисциплина</button>
                </form>
                <form class="catalog-list-tools subject-filters" method="GET" action="{{ route('catalog.index') }}">
                    <input type="hidden" name="tab" value="subjects">
                    <label>Учебен план
                        <select name="subject_plan_id" onchange="this.form.submit()">
                            <option value="">Всички планове</option>
                            @foreach($curriculumVersions as $version)
                                <option value="{{ $version->id }}" @selected($subjectPlanId === $version->id)>{{ $version->specialty->name }} · {{ $version->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Сортиране
                        <select name="subject_sort" onchange="this.form.submit()">
                            <option value="name" @selected($subjectSort === 'name')>По азбучен ред</option>
                            <option value="auditorium_hours" @selected($subjectSort === 'auditorium_hours')>По АЗ · низходящо</option>
                            <option value="code" @selected($subjectSort === 'code')>По код</option>
                        </select>
                    </label>
                    <label>Включване в учебен план
                        <select name="subject_in_curriculum" onchange="this.form.submit()">
                            <option value="" @selected($subjectCurriculumFilter === '')>Всички дисциплини</option>
                            <option value="included" @selected($subjectCurriculumFilter === 'included')>Включени в план</option>
                            <option value="not_included" @selected($subjectCurriculumFilter === 'not_included')>Не са включени в план</option>
                        </select>
                    </label>
                    <noscript><button class="button" type="submit">Приложи</button></noscript>
                </form>
                @php
                    $subjectsWithSelfStudy = $subjects->filter(fn ($subject): bool => (int) $subject->self_study_hours > 0);
                @endphp
                <section class="item" aria-labelledby="self-study-report">
                    <div class="row-head">
                        <strong id="self-study-report">Справка: дисциплини със самоподготовка</strong>
                        <span class="pill">{{ $subjectsWithSelfStudy->count() }}</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Код</th><th>Дисциплина</th><th>Самоподготовка</th></tr></thead>
                            <tbody>
                                @forelse($subjectsWithSelfStudy as $subject)
                                    <tr>
                                        <td>{{ $subject->code ?: '—' }}</td>
                                        <td>{{ $subject->name }}</td>
                                        <td>{{ $subject->self_study_hours }} ч.</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="empty">Няма дисциплини със зададени часове самоподготовка.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
                <div class="list three-columns">
                    @forelse($subjects as $subject)
                        <details class="item record-editor" id="subject-{{ $subject->id }}" @if((int) request('subject_id') === $subject->id) open @endif>
                            <summary class="record-summary">
                                <span class="row-head"><strong>{{ $subject->name }}</strong><span class="muted">{{ $subject->code }}</span></span>
                                <span class="muted">АЗ {{ $subject->auditorium_hours }} · Л {{ $subject->lecture_hours }} · У {{ $subject->exercise_hours }} · самоподготовка {{ $subject->self_study_hours }} · контрол {{ $subject->control_type ?: '—' }} · {{ $subject->curriculum_subjects_count }} включвания в планове</span>
                            </summary>
                            <div class="editor-panel">
                                <form class="edit-form" method="POST" action="{{ route('catalog.subjects.update', $subject) }}">
                                    @csrf @method('PATCH')
                                    <label>Код<input name="code" value="{{ $subject->code }}"></label>
                                    <label>Наименование<input name="name" value="{{ $subject->name }}" required></label>
                                    <label>АЗ<input name="auditorium_hours" type="number" min="0" max="1000" value="{{ $subject->auditorium_hours }}" required></label>
                                    <label>Лекции<input name="lecture_hours" type="number" min="0" max="1000" value="{{ $subject->lecture_hours }}" required></label>
                                    <label>Упражнения<input name="exercise_hours" type="number" min="0" max="1000" value="{{ $subject->exercise_hours }}" required></label>
                                    <label>Самоподготовка<input name="self_study_hours" type="number" min="0" max="1000" value="{{ $subject->self_study_hours }}" required></label>
                                    <label>Контрол<select name="control_type"><option value="" @selected(!$subject->control_type)>Не е зададен</option><option value="И" @selected($subject->control_type === 'И')>Изпит (И)</option><option value="ТО" @selected($subject->control_type === 'ТО')>Текущ контрол (ТО)</option><option value="П" @selected($subject->control_type === 'П')>Продължаваща дисциплина (П)</option></select></label>
                                    <button class="button" type="submit">Запази</button>
                                </form>
                                <form method="POST" action="{{ route('catalog.subjects.destroy', $subject) }}" onsubmit="return confirm('Изтриването премахва дисциплината от всички учебни планове, потоци и разписания. Продължаване?')">
                                    @csrf @method('DELETE')
                                    <button class="button" type="submit" style="color:#a83232">Изтрий дисциплината</button>
                                </form>
                            </div>
                        </details>
                    @empty
                        <div class="empty">Няма добавени дисциплини.</div>
                    @endforelse
                </div>
            </section>
            @endif

            @if($activeTab === 'teachers')
            <section class="card">
                <div class="section-head">
                    <div>
                        <h2>Преподаватели</h2>
                        <p>Отворете досието за редакция, предпочитани дни и обезпеченост ·
                            @if($teacherPlanId) {{ $teachers->count() }} преподаватели по избрания план.
                            @else {{ $teachers->count() }} общо, показани без пагинация. @endif
                        </p>
                    </div>
                    <a class="button primary" href="{{ route('catalog.teachers.create') }}">Добави преподавател</a>
                </div>
                <form class="catalog-list-tools subject-filters" method="GET" action="{{ route('catalog.index') }}">
                    <input type="hidden" name="tab" value="teachers">
                    <label>Преподаватели по учебен план
                        <select name="teacher_plan_id" onchange="this.form.submit()">
                            <option value="">Всички планове</option>
                            @foreach($curriculumVersions as $version)
                                <option value="{{ $version->id }}" @selected($teacherPlanId === $version->id)>{{ $version->specialty->name }} · {{ $version->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if($teacherPlanId)
                        <a class="button" href="{{ route('catalog.index', ['tab' => 'teachers']) }}">Изчисти</a>
                    @endif
                </form>
                <div class="list three-columns">
                    @forelse($teachers as $teacher)
                        <article class="item">
                            <a class="record-link" href="{{ route('catalog.teachers.show', $teacher) }}">
                                <span class="row-head"><strong>{{ $teacher->title }} {{ $teacher->first_name }} {{ $teacher->last_name }}</strong><span class="pill {{ $teacher->active ? '' : 'off' }}">{{ $teacher->active ? 'Активен' : 'Неактивен' }}</span></span>
                                <span class="muted">До {{ $teacher->max_daily_hours }} ч./ден · {{ $teacher->weekly_workload_hours }} ч./седмица · {{ $teacher->assignments_count }} дисциплини</span>
                            </a>
                        </article>
                    @empty
                        <div class="empty">Няма добавени преподаватели.</div>
                    @endforelse
                </div>
            </section>
            @endif

            @if($activeTab === 'organization')
            <section class="card">
                <div class="section-head"><div><h2>Зали и ателиета</h2><p>Типът и капацитетът ограничават автоматичното разпределение</p></div><span class="pill">{{ $rooms->count() }}</span></div>
                <form class="forms" method="POST" action="{{ route('catalog.rooms.store') }}">
                    @csrf
                    <label>Наименование<input name="name" required></label>
                    <label>Тип<select name="room_type"><option value="lecture">Лекционна</option><option value="atelier">Ателие</option><option value="painting">Живопис</option><option value="graphics">Графика</option><option value="computer">Компютърна</option><option value="studio">Студио</option></select></label>
                    <label>Капацитет<input name="capacity" type="number" min="1" value="25" required></label>
                    <label>Оборудване<input name="equipment" placeholder="проектор, стативи"></label>
                    <label class="span">Недостъпни периоди<input name="unavailable_periods" placeholder="1/2/08:00-10:00;3/7/13:00-15:00"><span class="help">Формат: седмица/ден/начало-край; дните са 1–7 (Понеделник–Неделя).</span></label>
                    <button class="button primary span" type="submit">Добави зала</button>
                </form>
                <div class="list">
                    @forelse($rooms as $room)
                        @php
                            $roomPeriods = collect($room->unavailable_periods ?? [])->map(fn ($period) => $period['cycle_week'].'/'.$period['weekday_no'].'/'.substr($period['start_time'], 0, 5).'-'.substr($period['end_time'], 0, 5))->implode(';');
                        @endphp
                        <details class="item record-editor">
                            <summary class="record-summary">
                                <span class="row-head"><strong>{{ $room->name }}</strong><span class="pill {{ $room->is_active ? '' : 'off' }}">{{ $room->is_active ? 'Активна' : 'Неактивна' }}</span></span>
                                <span class="muted">{{ $room->room_type }} · {{ $room->capacity }} места · {{ implode(', ', $room->equipment ?? []) }}</span>
                            </summary>
                            <div class="editor-panel">
                                <form class="edit-form" method="POST" action="{{ route('catalog.rooms.update', $room) }}">
                                    @csrf @method('PATCH')
                                    <label>Име<input name="name" value="{{ $room->name }}" required></label>
                                    <label>Тип<select name="room_type">@foreach(['lecture','atelier','computer','painting','graphics','studio'] as $type)<option value="{{ $type }}" @selected($room->room_type === $type)>{{ $type }}</option>@endforeach</select></label>
                                    <label>Капацитет<input name="capacity" type="number" min="1" max="1000" value="{{ $room->capacity }}" required></label>
                                    <label>Оборудване<input name="equipment" value="{{ implode(', ', $room->equipment ?? []) }}"></label>
                                    <label class="span">Недостъпни периоди<input name="unavailable_periods" value="{{ $roomPeriods }}" placeholder="1/2/08:00-10:00;3/7/13:00-15:00"></label>
                                    <label>Активност<select name="is_active"><option value="1" @selected($room->is_active)>Активна</option><option value="0" @selected(!$room->is_active)>Неактивна</option></select></label>
                                    <button class="button" type="submit">Запази</button>
                                </form>
                            </div>
                        </details>
                    @empty
                        <div class="empty">Няма добавени зали.</div>
                    @endforelse
                </div>
            </section>
            @endif
        </div>
    </main>
    <style>
        .catalog-list-tools { display:flex; gap:8px; align-items:center; padding:0 18px 12px; }
        .catalog-list-tools input { flex:1; min-width:0; min-height:34px; margin:0; }
        .catalog-list-tools label { flex:1; }
        .subject-filters { padding-top:12px; border-top:1px solid var(--line); }
        .catalog-list-tools select { width:100%; min-height:36px; border:1px solid var(--line); border-radius:8px; background:#fff; }
        .catalog-item-hidden { display:none !important; }
        @media(max-width:520px) { .catalog-list-tools { align-items:stretch; flex-direction:column; } }
    </style>
    <script>
        document.querySelectorAll('.card .list').forEach((list) => {
            const items = Array.from(list.querySelectorAll(':scope > .item'));
            if (items.length === 0) {
                return;
            }

            const tools = document.createElement('div');
            tools.className = 'catalog-list-tools';
            const search = document.createElement('input');
            search.type = 'search';
            search.placeholder = 'Търсене в списъка';
            search.setAttribute('aria-label', 'Търсене в списъка');

            const update = () => {
                const query = search.value.trim().toLocaleLowerCase('bg');
                items.forEach((item) => {
                    const matches = item.textContent.toLocaleLowerCase('bg').includes(query);
                    item.classList.toggle('catalog-item-hidden', !matches);
                });
            };

            search.addEventListener('input', update);

            tools.append(search);
            list.before(tools);
            update();
        });
    </script>
</body>
</html>
