@extends('layouts.app')

@section('content')
<div class="container">
    <h2>Dashboard</h2>

    @if(auth()->user()->hasRole('admin'))
        <div class="alert alert-danger">
            Влезли сте като Admin
        </div>
    @elseif(auth()->user()->hasRole('manager'))
        <div class="alert alert-info">
            Влезли сте като Manager
        </div>
    @else
        <div class="alert alert-secondary">
            Влезли сте като User
        </div>
    @endif

    <div class="mt-4 d-flex gap-2">
        <a href="{{ route('plans.index') }}" class="btn btn-primary">Планове</a>

        @role('admin')
            <a href="{{ route('admin.users') }}" class="btn btn-warning">Управление на потребители</a>
        @endrole
    </div>
</div>
@endsection