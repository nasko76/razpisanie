<!doctype html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Нов преподавател · ФИИ</title>
    <style>
        :root { --ink:#17231d; --muted:#6b7771; --line:#e3e9e5; --paper:#fff; --canvas:#f3f6f3; --green:#176b4a; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--canvas); color:var(--ink); font:14px/1.45 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        a { color:inherit; text-decoration:none; }
        .shell { max-width:760px; margin:auto; padding:24px clamp(14px,3vw,40px) 48px; }
        .topbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:30px; color:var(--muted); font-size:13px; }
        .brand { display:flex; align-items:center; gap:10px; font-weight:750; }
        .mark { display:grid; width:38px; height:38px; place-items:center; border-radius:12px; background:var(--green); color:#fff; font:20px Georgia,serif; }
        h1 { margin:5px 0 20px; font-size:clamp(25px,3vw,34px); letter-spacing:-.04em; }
        h2 { margin:0; font-size:16px; }
        .eyebrow { color:var(--green); font-size:10px; font-weight:800; letter-spacing:.13em; text-transform:uppercase; }
        .muted { color:var(--muted); font-size:12px; }
        .card { overflow:hidden; border:1px solid var(--line); border-radius:14px; background:var(--paper); box-shadow:0 6px 24px rgba(32,54,41,.035); }
        .card-head,.card-body { padding:18px 20px; }
        .card-head { border-bottom:1px solid var(--line); }
        .form { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
        label { display:block; color:var(--muted); font-size:11px; font-weight:700; }
        input { display:block; width:100%; min-height:40px; margin-top:5px; padding:0 10px; border:1px solid var(--line); border-radius:8px; background:white; color:var(--ink); }
        .span { grid-column:1/-1; }
        .button { display:inline-flex; min-height:38px; align-items:center; justify-content:center; padding:0 12px; border:1px solid var(--line); border-radius:9px; background:#fff; font-weight:650; cursor:pointer; }
        .button.primary { border-color:var(--green); background:var(--green); color:#fff; }
        .notice { margin-bottom:14px; padding:12px 14px; border:1px solid #efc7b5; border-radius:10px; background:#fff6ef; color:#8d401c; }
        @media(max-width:520px) { .form { grid-template-columns:1fr; } .span { grid-column:auto; } }
    </style>
</head>
<body>
<main class="shell">
    <header class="topbar">
        <a class="brand" href="{{ route('catalog.index') }}"><span class="mark">Ф</span><span>ФИИ · Учебен каталог</span></a>
        <a href="{{ route('catalog.index') }}">Назад към каталога</a>
    </header>

    @if($errors->any())<div class="notice">Проверете въведените данни: {{ $errors->first() }}</div>@endif

    <div class="eyebrow">Досие на преподавател</div>
    <h1>Добавяне на преподавател</h1>

    <section class="card">
        <header class="card-head"><h2>Основни данни</h2><p class="muted">Предпочитанията и обезпечеността се настройват в създаденото досие.</p></header>
        <form class="card-body form" method="POST" action="{{ route('catalog.teachers.store') }}">
            @csrf
            <label>Име<input name="first_name" value="{{ old('first_name') }}" required maxlength="100"></label>
            <label>Фамилия<input name="last_name" value="{{ old('last_name') }}" required maxlength="100"></label>
            <label class="span">Академична длъжност<input name="title" value="{{ old('title') }}" maxlength="100" placeholder="доц. д-р"></label>
            <label>Максимум часове на ден<input name="max_daily_hours" type="number" min="1" max="16" value="{{ old('max_daily_hours', 8) }}" required></label>
            <label>Натовареност на седмица<input name="weekly_workload_hours" type="number" min="0" max="80" value="{{ old('weekly_workload_hours', 0) }}" required></label>
            <button class="button primary span" type="submit">Създай досие</button>
        </form>
    </section>
</main>
</body>
</html>
