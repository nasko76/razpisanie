<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <script src="{{ asset('js/app.js') }}" defer></script>
</head>
<body>
    <div class="container py-4">
        <nav class="navbar navbar-expand-lg navbar-light bg-light mb-4 rounded">
            <div class="container-fluid">
                <a class="navbar-brand" href="{{ url('/') }}">{{ config('app.name', 'Laravel') }}</a>

                <div class="ms-auto d-flex align-items-center gap-3">
                    @auth
                        <a class="nav-link" href="{{ route('dashboard') }}">Dashboard</a>

                        @role('admin')
                            <a class="nav-link" href="{{ route('admin.users') }}">Admin</a>
                        @endrole

                        <a class="nav-link" href="{{ route('plans.index') }}">Планове</a>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="btn btn-link nav-link">Logout</button>
                        </form>
                    @endauth
                </div>
            </div>
        </nav>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        @yield('content')
    </div>
</body>
</html>