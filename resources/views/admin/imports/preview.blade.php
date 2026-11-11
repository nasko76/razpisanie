<!doctype html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Преглед на импорт · ФИИ</title>
    <style>
        :root { --ink:#17231d; --muted:#6b7771; --line:#e3e9e5; --paper:#fff; --canvas:#f3f6f3; --green:#176b4a; --soft:#e8f4ed; --red:#8d401c; --amber:#825b10; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--canvas); color:var(--ink); font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        a { color:inherit; }
        .shell { max-width:1200px; margin:auto; padding:24px 18px 48px; }
        .top,.heading,.row-title { display:flex; align-items:center; justify-content:space-between; gap:16px; }
        .top { margin-bottom:24px; color:var(--muted); font-size:13px; }
        .heading { align-items:end; margin-bottom:18px; }
        h1 { margin:5px 0; font-size:30px; letter-spacing:-.04em; }
        h2 { margin:0; font-size:16px; }
        p { margin:4px 0; color:var(--muted); }
        .eyebrow { color:var(--green); font-size:10px; font-weight:800; letter-spacing:.13em; text-transform:uppercase; }
        .card { margin:12px 0; padding:16px; border:1px solid var(--line); border-radius:12px; background:var(--paper); }
        .row-title { align-items:flex-start; }
        .pill { display:inline-flex; padding:3px 8px; border-radius:20px; background:var(--soft); color:var(--green); font-size:11px; font-weight:750; white-space:nowrap; }
        .pill.warn { background:#fff2dc; color:var(--amber); }
        .pill.error { background:#fff0e8; color:var(--red); }
        .resolution { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin-top:12px; }
        label { display:block; min-width:0; color:var(--muted); font-size:12px; font-weight:700; }
        select { display:block; width:100%; min-height:38px; margin-top:5px; padding:0 9px; border:1px solid var(--line); border-radius:8px; background:#fff; color:var(--ink); font:inherit; }
        .warnings { margin:10px 0 0; color:var(--red); font-size:12px; }
        .source { margin-top:10px; border-top:1px solid var(--line); }
        summary { margin-top:9px; color:var(--green); font-size:12px; font-weight:700; cursor:pointer; }
        .source dl { display:grid; grid-template-columns:minmax(140px, .35fr) 1fr; gap:5px 12px; margin:9px 0 0; font-size:12px; }
        dt { color:var(--muted); } dd { margin:0; overflow-wrap:anywhere; }
        .actions { display:flex; gap:10px; align-items:center; margin-top:18px; }
        .button { display:inline-flex; min-height:40px; align-items:center; justify-content:center; padding:0 14px; border:1px solid var(--line); border-radius:9px; background:#fff; color:var(--ink); font:inherit; font-weight:700; text-decoration:none; cursor:pointer; }
        .button.primary { border-color:var(--green); background:var(--green); color:#fff; }
        .notice { margin:14px 0; padding:12px 14px; border:1px solid #efc7b5; border-radius:10px; background:#fff6ef; color:var(--red); }
        @media(max-width:700px) { .heading,.top { align-items:flex-start; flex-direction:column; } .resolution { grid-template-columns:1fr; } .source dl { grid-template-columns:1fr; gap:1px; } dd { margin-bottom:6px; } }
    </style>
</head>
<body>
    <main class="shell">
        <nav class="top"><a href="{{ route('imports.index') }}">← Към импорта</a><a href="{{ route('catalog.index') }}">Учебен каталог</a></nav>
        <header class="heading">
            <div>
                <div class="eyebrow">Преглед · {{ $preview['kind'] === 'curriculum' ? 'Учебен план' : 'Преподавателска обезпеченост' }}</div>
                <h1>{{ $preview['kind'] === 'curriculum' ? $preview['specialty_name'].' · '.$preview['version_name'] : $preview['version_name'] }}</h1>
                <p>{{ count($preview['rows']) }} разпознати реда. Проверете предложенията и изберете изрично кои да се запишат.</p>
            </div>
        </header>
        <div class="notice">Неразпознатите колони се запазват като изходни данни. При PlanAZ преподавателят се възлага към посочената група и тип занятия (Л/У); можете да промените избора преди запис. При повторен импорт еднаквите възлагания не се дублират, а променените редове се обновяват.</div>
        @if($errors->any())
            <div class="notice">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
        @endif

        <form method="POST" action="{{ route('imports.confirm') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            @foreach($preview['rows'] as $index => $row)
                @php
                    $isCurriculum = $preview['kind'] === 'curriculum';
                    $status = $row['status'];
                    $skipByDefault = $isCurriculum && ($row['default_skip'] ?? false);
                    $statusLabel = $status === 'already_imported' ? 'Вече е в този план' : ($status === 'matched' ? 'Намерено съвпадение' : ($status === 'ambiguous' ? 'Няколко съвпадения' : 'Няма съвпадение'));
                    $statusClass = in_array($status, ['matched', 'already_imported'], true) ? '' : ($status === 'ambiguous' ? 'warn' : 'error');
                @endphp
                <article class="card">
                    <div class="row-title">
                        <div>
                            <h2>{{ $isCurriculum ? $row['subject_name'] : $row['subject_name'].' · '.$row['teacher_name'] }}</h2>
                            <p>
                                @if($isCurriculum)
                                    {{ $row['subject_code'] ?: 'Без код' }} · {{ $row['course_year'] ? $row['course_year'].' курс' : 'Курс неразпознат' }} · {{ $row['semester_no'] ? 'семестър '.$row['semester_no'] : 'Семестър неразпознат' }} · {{ ['mandatory' => 'Задължителна', 'elective' => 'Избираема дисциплина', 'optional' => 'Факултативна дисциплина'][$row['subject_category']] }}
                                @else
                                    {{ $row['specialty_name'] }} · {{ $row['course_year'] ? $row['course_year'].' курс' : 'Курс неразпознат' }} · {{ $row['semester_no'] ? 'семестър '.$row['semester_no'] : 'Семестър неразпознат' }} · {{ $row['group_name'] ? 'група '.$row['group_name'] : 'без група' }} · {{ $row['coverage_label'] ?: 'Л/У неразпознато' }} · {{ $row['planned_hours'] ?? 'часове неразпознати' }} ч.
                                @endif
                            </p>
                        </div>
                        <span class="pill {{ $statusClass }}">{{ $statusLabel }}</span>
                    </div>

                    @if($isCurriculum)
                        <p>АЗ: {{ $row['auditorium_hours'] ?? '—' }} · Л: {{ $row['lecture_hours'] ?? '—' }} · У: {{ $row['exercise_hours'] ?? '—' }} · Самоподготовка: {{ $row['self_study_hours'] ?? 0 }} · Контрол: {{ $row['normalized_control_type'] ?? '—' }}</p>
                        @if($skipByDefault)
                            <p class="warnings">Няма зададена АЗ; редът е маркиран за пропускане по подразбиране.</p>
                        @endif
                    @else
                        <p>Катедра: {{ $row['department_name'] ?: 'не е разпозната' }} · Студенти в групата: {{ $row['student_count'] }} · Планирани часове за преподавателя: {{ $row['planned_hours'] ?? '—' }} · Общо възложени часове: {{ $row['teacher_total_assigned_hours'] ?? '—' }}</p>
                    @endif

                    @if($row['warnings'])
                        <ul class="warnings">@foreach($row['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul>
                    @endif

                    <div class="resolution">
                        <label>{{ $isCurriculum ? 'Дисциплина' : 'Съвпадение на дисциплина' }}
                            <select name="subjects[{{ $index }}]">
                                <option value="skip" @selected($skipByDefault)>Пропусни реда</option>
                                @foreach($row['subject_candidates'] as $candidate)
                                    <option value="{{ $candidate['id'] }}" @selected(!$skipByDefault && ($isCurriculum ? $status : $row['subject_status']) === 'matched' && count($row['subject_candidates']) === 1)>{{ $candidate['name'] }}{{ !empty($candidate['code']) ? ' · '.$candidate['code'] : '' }} (съществуваща)</option>
                                @endforeach
                                @if($row['valid'] && $status !== 'already_imported' && ($row['subject_candidates'] ?? []) === [])
                                    <option value="create" @selected(!$skipByDefault)>Създай „{{ $row['subject_name'] }}“ след потвърждение</option>
                                @endif
                            </select>
                        </label>
                        @if(!$isCurriculum)
                            <label>Преподавател
                                <select name="teachers[{{ $index }}]">
                                    <option value="skip">Пропусни реда</option>
                                    @foreach($row['teacher_candidates'] as $candidate)
                                        <option value="{{ $candidate['id'] }}" @selected($row['teacher_status'] === 'matched' && count($row['teacher_candidates']) === 1)>{{ $candidate['name'] }} (съществуващ)</option>
                                    @endforeach
                                    @if($row['valid'] && $row['teacher_candidates'] === [])
                                        <option value="create" selected>Създай „{{ $row['teacher_name'] }}“ след потвърждение (натовареността остава 0)</option>
                                    @endif
                                </select>
                            </label>
                        @endif
                    </div>

                    <details class="source">
                        <summary>Показване на всички изходни колони</summary>
                        <dl>
                            @foreach($row['source'] as $label => $value)
                                <dt>{{ $label }}</dt><dd>{{ $value !== '' ? $value : '—' }}</dd>
                            @endforeach
                        </dl>
                    </details>
                </article>
            @endforeach
            <div class="actions">
                <button class="button primary" type="submit">Потвърди и запиши избраните редове</button>
                <a class="button" href="{{ route('imports.index') }}">Отказ</a>
            </div>
        </form>
    </main>
</body>
</html>
