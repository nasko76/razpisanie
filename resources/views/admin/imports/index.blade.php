<!doctype html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Импорт на учебни данни · ФИИ</title>
    <style>
        :root { --ink:#17231d; --muted:#6b7771; --line:#e3e9e5; --paper:#fff; --canvas:#f3f6f3; --green:#176b4a; --soft:#e8f4ed; --red:#8d401c; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--canvas); color:var(--ink); font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        a { color:inherit; }
        .shell { max-width:860px; margin:auto; padding:24px 18px 48px; }
        .top,.heading { display:flex; align-items:center; justify-content:space-between; gap:16px; }
        .top { margin-bottom:30px; color:var(--muted); font-size:13px; }
        .heading { align-items:end; margin-bottom:20px; }
        h1 { margin:5px 0; font-size:30px; letter-spacing:-.04em; }
        h2 { margin:0; font-size:16px; }
        p { color:var(--muted); }
        .eyebrow { color:var(--green); font-size:10px; font-weight:800; letter-spacing:.13em; text-transform:uppercase; }
        .card { padding:20px; border:1px solid var(--line); border-radius:14px; background:var(--paper); box-shadow:0 6px 24px rgba(32,54,41,.035); }
        .form { display:grid; gap:16px; }
        label { display:block; color:var(--muted); font-size:12px; font-weight:700; }
        input,select { display:block; width:100%; min-height:40px; margin-top:6px; padding:0 10px; border:1px solid var(--line); border-radius:8px; background:#fff; color:var(--ink); font:inherit; }
        .button { display:inline-flex; min-height:40px; align-items:center; justify-content:center; padding:0 14px; border:1px solid var(--line); border-radius:9px; background:#fff; color:var(--ink); font:inherit; font-weight:700; text-decoration:none; cursor:pointer; }
        .button.primary { border-color:var(--green); background:var(--green); color:#fff; }
        .notice { margin:14px 0; padding:12px 14px; border:1px solid #efc7b5; border-radius:10px; background:#fff6ef; color:var(--red); }
        .help { margin:6px 0 0; font-size:12px; }
        ul { margin:6px 0 0; padding-left:20px; }
        @media(max-width:600px) { .heading,.top { align-items:flex-start; flex-direction:column; } }
    </style>
</head>
<body>
    <main class="shell">
        <nav class="top"><a href="{{ route('catalog.index') }}">← Към учебния каталог</a><a href="{{ route('schedules.index') }}">Към разписанието</a></nav>
        <header class="heading">
            <div>
                <div class="eyebrow">Администрация · Импорт</div>
                <h1>Импорт на учебни данни</h1>
                <p>Първо ще видите разпознатите редове и съвпаденията. Нищо не се записва преди изрично потвърждение.</p>
            </div>
        </header>

        @if($errors->any())
            <div class="notice">Неуспешен импорт:
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <section class="card">
            <form class="form" method="POST" action="{{ route('imports.preview') }}" enctype="multipart/form-data">
                @csrf
                <label>Данни за импорт
                    <select name="kind" required>
                        <option value="curriculum" @selected(old('kind', 'curriculum') === 'curriculum')>Учебен план от запазена HTML страница</option>
                        <option value="teachers" @selected(old('kind') === 'teachers')>Преподавателска обезпеченост от HTML/Excel .xls</option>
                    </select>
                </label>
                <label>Версия на учебния план
                    <select name="curriculum_version_id" required>
                        <option value="">Изберете версия</option>
                        @foreach($curriculumVersions as $version)
                            <option value="{{ $version->id }}" @selected((string) old('curriculum_version_id') === (string) $version->id)>{{ $version->specialty->name }} · {{ $version->name }} ({{ $version->version_year }})</option>
                        @endforeach
                    </select>
                </label>
                <label>Файл
                    <input type="file" name="file" accept=".html,.htm,.xls" required>
                    <p class="help">До 20 MB. Поддържат се HTML/HTM и .xls файл, записан като HTML таблица. Бинарни .xls и .xlsx не се поддържат. Възрастта на преподавателите не се импортира.</p>
                </label>
                @if($curriculumVersions->isEmpty())
                    <div class="notice">Преди импорт създайте специалност и версия на учебния план в каталога.</div>
                @else
                    <button class="button primary" type="submit">Провери файла</button>
                @endif
            </form>
        </section>
    </main>
</body>
</html>
