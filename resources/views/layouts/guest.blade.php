<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Вход · {{ config('app.name', 'Учебно разписание') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { min-height:100vh; margin:0; background:#f3f6f3; color:#17231d; font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        .auth-shell { display:grid; min-height:100vh; grid-template-columns:minmax(0,1.05fr) minmax(400px,.95fr); }
        .auth-story { position:relative; display:flex; flex-direction:column; justify-content:space-between; overflow:hidden; padding:clamp(32px,6vw,80px); background:#14583e; color:white; }
        .auth-story::after { position:absolute; right:-130px; bottom:-170px; width:520px; height:520px; border:1px solid rgba(255,255,255,.15); border-radius:50%; box-shadow:0 0 0 45px rgba(255,255,255,.035),0 0 0 95px rgba(255,255,255,.025); content:""; }
        .auth-brand { position:relative; z-index:1; display:flex; align-items:center; gap:12px; font-size:14px; font-weight:750; }
        .auth-mark { display:grid; width:42px; height:42px; place-items:center; border-radius:13px; background:rgba(255,255,255,.14); font:22px Georgia,serif; }
        .auth-copy { position:relative; z-index:1; max-width:540px; padding:60px 0; }
        .auth-eyebrow { color:#b9e3ca; font-size:11px; font-weight:800; letter-spacing:.14em; text-transform:uppercase; }
        .auth-copy h1 { margin:13px 0; font-size:clamp(34px,5vw,62px); line-height:1.04; letter-spacing:-.05em; }
        .auth-copy p { max-width:450px; color:#d5e7db; font-size:16px; line-height:1.7; }
        .auth-story a { position:relative; z-index:1; color:#e7f3eb; font-size:13px; text-decoration:none; }
        .auth-main { display:grid; place-items:center; padding:clamp(24px,6vw,80px); }
        .auth-card { width:min(100%,440px); padding:clamp(24px,4vw,40px); border:1px solid #e3e9e5; border-radius:20px; background:white; box-shadow:0 18px 55px rgba(32,54,41,.08); }
        @media(max-width:800px) { .auth-shell { grid-template-columns:1fr; } .auth-story { min-height:275px; padding:25px; } .auth-copy { padding:38px 0 20px; } .auth-copy h1 { font-size:36px; } .auth-copy p { margin-bottom:0; font-size:14px; } .auth-story>a { margin-top:22px; } .auth-main { padding:24px 16px 38px; } }
    </style>
</head>
<body>
    <div class="auth-shell">
        <aside class="auth-story">
            <a class="auth-brand" href="{{ route('home') }}"><span class="auth-mark">Ф</span><span>ФИИ · Учебно разписание</span></a>
            <div class="auth-copy">
                <div class="auth-eyebrow">Работно пространство</div>
                <h1>Планиране на занятията, подредено.</h1>
                <p>Управлявайте учебните планове, преподавателската обезпеченост и седмичните разписания на едно място.</p>
            </div>
            <a href="{{ route('home') }}">Разгледайте публичното разписание →</a>
        </aside>
        <main class="auth-main">
            <section class="auth-card">
                {{ $slot }}
            </section>
        </main>
    </div>
</body>
</html>