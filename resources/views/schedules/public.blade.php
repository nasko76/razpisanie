<!doctype html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Учебно разписание · ФИИ</title>
    <style>
        :root { color-scheme: light; --ink:#17231d; --muted:#6b7771; --line:#e3e9e5; --paper:#fff; --canvas:#f3f6f3; --green:#176b4a; --soft:#e8f4ed; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--canvas); color:var(--ink); font:14px/1.45 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        a { color:inherit; text-decoration:none; }
        button,select { font:inherit; }
        .shell { max-width:1420px; margin:auto; padding:24px clamp(14px,3vw,40px) 48px; }
        .topbar,.heading,.schedule-head { display:flex; align-items:center; justify-content:space-between; gap:18px; }
        .topbar { margin-bottom:36px; color:var(--muted); font-size:13px; }
        .brand { display:flex; align-items:center; gap:11px; color:var(--ink); font-weight:750; }
        .mark { display:grid; width:40px; height:40px; place-items:center; border-radius:13px; background:var(--green); color:#fff; font:21px Georgia,serif; }
        .toplinks { display:flex; gap:16px; }
        .heading { align-items:end; margin-bottom:22px; }
        .eyebrow { color:var(--green); font-size:10px; font-weight:800; letter-spacing:.13em; text-transform:uppercase; }
        h1 { margin:6px 0; font-size:clamp(26px,3vw,36px); letter-spacing:-.04em; }
        h2 { margin:0; font-size:16px; }
        .lede,.muted { color:var(--muted); }
        .lede { margin:0; }
        .layout { display:grid; grid-template-columns:minmax(230px,300px) minmax(0,1fr); align-items:start; gap:18px; }
        .panel { overflow:hidden; border:1px solid var(--line); border-radius:15px; background:var(--paper); box-shadow:0 7px 25px rgba(32,54,41,.035); }
        .filters { padding:20px; }
        .filters h2 { margin-bottom:14px; }
        label { display:block; margin:13px 0 6px; color:var(--muted); font-size:12px; font-weight:650; }
        select { width:100%; min-height:42px; padding:0 11px; border:1px solid var(--line); border-radius:9px; background:white; color:var(--ink); }
        .button { display:inline-flex; min-height:42px; align-items:center; justify-content:center; padding:0 15px; border:1px solid var(--line); border-radius:10px; background:#fff; color:var(--ink); font-weight:650; cursor:pointer; }
        .button.primary { border-color:var(--green); background:var(--green); color:#fff; }
        .filters .button { width:100%; margin-top:17px; }
        .term { margin-top:19px; padding-top:15px; border-top:1px solid var(--line); color:var(--muted); font-size:12px; }
        .term strong { display:block; margin-top:4px; color:var(--ink); font-size:13px; }
        .schedule-head { padding:17px 19px; border-bottom:1px solid var(--line); }
        .cycle { display:flex; gap:7px; }
        .cycle a { padding:7px 10px; border:1px solid var(--line); border-radius:9px; color:var(--muted); font-size:12px; font-weight:700; white-space:nowrap; }
        .cycle a.active { border-color:var(--green); background:var(--soft); color:var(--green); }
        .grid-wrap { overflow-x:auto; padding:12px; }
        table { width:100%; min-width:850px; border-collapse:separate; border-spacing:6px; table-layout:fixed; }
        th { padding:8px; color:var(--muted); font-size:11px; font-weight:750; text-align:left; text-transform:uppercase; letter-spacing:.06em; }
        th:first-child { width:120px; }
        td { vertical-align:top; }
        .block-label { min-height:100px; padding:12px 10px; border-radius:10px; background:#f6f8f6; color:var(--muted); font-size:11px; }
        .block-label strong { display:block; margin-bottom:5px; color:var(--ink); font-size:12px; }
        .cell { min-height:100px; padding:5px; border:1px solid #edf1ed; border-radius:10px; background:#fbfcfb; }
        .lesson { margin-bottom:5px; padding:9px; border:1px solid #dfe9e2; border-left:3px solid var(--green); border-radius:8px; background:#f3faf5; }
        .lesson:last-child { margin-bottom:0; }
        .lesson-title { display:block; font-size:12px; font-weight:750; }
        .lesson-meta { display:block; margin-top:4px; color:var(--muted); font-size:10px; line-height:1.45; }
        .empty-cell { display:grid; min-height:88px; place-items:center; color:#a2ada6; font-size:11px; }
        .empty-state { padding:48px 22px; text-align:center; }
        .empty-state .symbol { font-size:32px; }
        .empty-state h2 { margin:8px 0; }
        .empty-state p { max-width:420px; margin:0 auto; color:var(--muted); }
        .mobile-note { display:none; padding:0 19px 14px; color:var(--muted); font-size:11px; }
        @media(max-width:900px) { .layout { grid-template-columns:1fr; } .heading { align-items:start; flex-direction:column; } }
        @media(max-width:560px) { .shell { padding:17px 12px 32px; } .topbar { margin-bottom:25px; } .brand span:last-child { display:none; } .toplinks { gap:9px; } .schedule-head { align-items:start; flex-direction:column; } .mobile-note { display:block; } }
    </style>
</head>
<body>
    <main class="shell">
        <header class="topbar">
            <a class="brand" href="{{ route('home') }}"><span class="mark">Ф</span><span>ФИИ · Учебно разписание</span></a>
            <nav class="toplinks"><a href="{{ route('login') }}">Вход за администрация</a></nav>
        </header>

        <section class="heading">
            <div>
                <div class="eyebrow">Публично разписание</div>
                <h1>Учебно разписание</h1>
                <p class="lede">Изберете специалност и курс, за да видите актуалния график.</p>
            </div>
        </section>
        @if($publicScheduleNotice)
            <div class="panel" style="margin-bottom:16px;padding:14px 18px">{{ $publicScheduleNotice }}</div>
        @endif

        <div class="layout">
            <form class="panel filters" method="GET" action="{{ route('home') }}" id="schedule-filters">
                <h2>Избор на разписание</h2>
                <label for="specialty_id">Специалност</label>
                <select id="specialty_id" name="specialty_id" required>
                    <option value="">Изберете специалност</option>
                    @foreach($specialties as $specialty)
                        <option value="{{ $specialty->id }}" @selected($selectedSpecialtyId === $specialty->id)>{{ $specialty->name }}</option>
                    @endforeach
                </select>

                <label for="course_year">Курс</label>
                <select id="course_year" name="course_year" required>
                    <option value="">Изберете курс</option>
                    @for($year = 1; $year <= 6; $year++)
                        <option value="{{ $year }}" @selected($selectedCourseYear === $year)>{{ $year }}. курс</option>
                    @endfor
                </select>

                <div id="group-filter" @if(!$hasSelection || $groups->isEmpty()) hidden @endif>
                    <label for="group_id">Група <span class="muted">(по избор)</span></label>
                    <select id="group_id" name="group_id">
                        <option value="">Целият курс</option>
                        @foreach($groups as $group)
                            <option value="{{ $group->id }}" @selected($selectedGroup?->id === $group->id)>{{ $group->display_name }}</option>
                        @endforeach
                    </select>
                </div>

                @if($hasSelection)
                    <input type="hidden" name="cycle_week" value="{{ $cycleWeek }}">
                @endif
                <button class="button primary" type="submit">Покажи разписанието</button>

                @if($hasSelection)
                    <div class="term">
                        Актуална учебна година
                        <strong>{{ $academicYear }}</strong>
                        <strong>{{ $semesterLabel }}</strong>
                    </div>
                @endif
            </form>

            <section class="panel">
                @if(!$hasSelection)
                    <div class="empty-state">
                        <div class="symbol">▦</div>
                        <h2>Изберете специалност и курс</h2>
                        <p>Разписанието ще се покаже тук. Учебната година и семестърът се определят автоматично.</p>
                    </div>
                @else
                    <header class="schedule-head">
                        <div>
                            <h2>{{ $selectedGroup ? $selectedGroup->display_name : $selectedCourseYear.'. курс' }}</h2>
                            <span class="muted">{{ $specialties->firstWhere('id', $selectedSpecialtyId)?->name }} · {{ $academicYear }} · {{ $semesterLabel }}</span>
                        </div>
                        <nav class="cycle" aria-label="Седмица от цикъла">
                            @foreach([1, 2, 3] as $week)
                                <a class="{{ $cycleWeek === $week ? 'active' : '' }}" href="{{ route('home', request()->except('cycle_week') + ['cycle_week' => $week]) }}" aria-current="{{ $cycleWeek === $week ? 'page' : 'false' }}">Седмица {{ $week }}</a>
                            @endforeach
                        </nav>
                    </header>
                    <p class="mobile-note">Плъзнете таблицата настрани, за да видите всички дни.</p>
                    <div class="grid-wrap">
                        <table>
                            <thead><tr><th>Част от деня</th>@foreach($days as $day)<th>{{ $day }}</th>@endforeach</tr></thead>
                            <tbody>
                                @foreach($dayParts as $part => $definition)
                                    <tr>
                                        <th><div class="block-label"><strong>{{ $definition['label'] }}</strong></div></th>
                                        @foreach($days as $dayNo => $day)
                                            <td><div class="cell">
                                                @forelse($schedule[$dayNo][$part] ?? [] as $entry)
                                                    <article class="lesson">
                                                        <span class="lesson-title">{{ $entry->custom_subject_name ?: $entry->subject?->name }}</span>
                                                        @php
                                                            $subjectTeacherNames = $teacherNamesBySubject[$entry->group_id.':'.$entry->subject_id] ?? [];
                                                            if ($subjectTeacherNames === [] && $entry->teacher) {
                                                                $coverageLabel = $entry->coverage_type === 'lecture' ? 'Лекции: ' : ($entry->coverage_type === 'exercise' ? 'Упражнения: ' : '');
                                                                $subjectTeacherNames[] = $coverageLabel.trim(($entry->teacher->title ? $entry->teacher->title.' ' : '').$entry->teacher->first_name.' '.$entry->teacher->last_name);
                                                            }
                                                        @endphp
                                                        <span class="lesson-meta">{{ substr($entry->start_time, 0, 5) }}–{{ substr($entry->end_time, 0, 5) }}@if($subjectTeacherNames !== []) · {{ implode(', ', $subjectTeacherNames) }}@endif @if($entry->room) · {{ $entry->room->name }}@endif</span>
                                                        @if($entry->studentFlow)<span class="lesson-meta">Общ поток: {{ $entry->studentFlow->name }}</span>@endif
                                                        @if(!$selectedGroup)<span class="lesson-meta">{{ $entry->group?->display_name }}</span>@endif
                                                        @if($entry->note)<span class="lesson-meta">{{ $entry->note }}</span>@endif
                                                    </article>
                                                @empty
                                                    <div class="empty-cell">—</div>
                                                @endforelse
                                            </div></td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    </main>
    <script>
        const groups = @json($groupOptions);
        const specialty = document.getElementById('specialty_id');
        const course = document.getElementById('course_year');
        const groupFilter = document.getElementById('group-filter');
        const groupSelect = document.getElementById('group_id');
        const selectedGroup = @json($selectedGroup?->id);

        function refreshGroups() {
            if (!groupSelect) {
                return;
            }

            const available = groups.filter((group) =>
                String(group.specialty_id) === specialty.value && String(group.course_year) === course.value
            );
            groupSelect.replaceChildren(new Option('Целият курс', ''));
            available.forEach((group) => {
                const option = new Option(group.group_name, group.id);
                option.selected = Number(selectedGroup) === Number(group.id);
                groupSelect.add(option);
            });
            groupFilter.hidden = available.length === 0;
        }

        specialty.addEventListener('change', refreshGroups);
        course.addEventListener('change', refreshGroups);
    </script>
</body>
</html>
