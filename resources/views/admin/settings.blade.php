<!doctype html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Общи настройки · ФИИ</title>
    <style>
        :root { --ink:#17231d; --muted:#6b7771; --line:#e3e9e5; --paper:#fff; --canvas:#f3f6f3; --green:#176b4a; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--canvas); color:var(--ink); font:14px/1.45 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        a { color:inherit; text-decoration:none; }
        .shell { max-width:900px; margin:auto; padding:24px clamp(14px,3vw,40px) 48px; }
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
        .setting { display:flex; align-items:center; justify-content:space-between; gap:18px; }
        .setting label { font-weight:700; }
        .setting input { width:20px; height:20px; accent-color:var(--green); }
        .button { display:inline-flex; min-height:38px; align-items:center; justify-content:center; padding:0 12px; border:1px solid var(--line); border-radius:9px; background:#fff; font-weight:650; cursor:pointer; }
        .button.primary { border-color:var(--green); background:var(--green); color:#fff; }
        .button.danger { color:#a83232; }
        .notice { margin-bottom:14px; padding:12px 14px; border:1px solid #cfe7d6; border-radius:10px; background:#edf8f0; color:#20583c; }
        .errors { border-color:#efc7b5; background:#fff6ef; color:#8d401c; }
    </style>
</head>
<body>
<main class="shell">
    <header class="topbar">
        <a class="brand" href="{{ route('catalog.index') }}"><span class="mark">Ф</span><span>ФИИ · Учебен каталог</span></a>
        <a href="{{ route('catalog.index') }}">Назад към каталога</a>
    </header>

    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="notice errors">{{ $errors->first() }}</div>@endif

    <div class="eyebrow">Администрация</div>
    <h1>Общи настройки</h1>

    <section class="card">
        <header class="card-head">
            <h2>Разписание</h2>
            <p class="muted">Съобщение, което се вижда на публичната начална страница.</p>
        </header>
        <form class="card-body" method="POST" action="{{ route('settings.update') }}">
            @csrf @method('PUT')
            <div class="setting">
                <div>
                    <label for="public_schedule_notice">Съобщение към студентите</label>
                    <div class="muted">Например информация за промяна на зали или срокове. Оставете празно, за да не се показва съобщение.</div>
                </div>
            </div>
            <textarea id="public_schedule_notice" name="public_schedule_notice" rows="4" maxlength="1000" style="width:100%;margin-top:12px;padding:10px;border:1px solid var(--line);border-radius:8px">{{ $publicScheduleNotice }}</textarea>
            <button class="button primary" type="submit" style="margin-top:18px">Запази настройките</button>
        </form>
    </section>

</main>
</body>
</html>
