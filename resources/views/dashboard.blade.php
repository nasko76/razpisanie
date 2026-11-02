@extends('layouts.app')

@section('content')
    <div class="card">
        <h2>Dashboard</h2>
        <p>Добре дошли в админ панела.</p>

        <div class="row" style="margin-top: 24px;">
            <div class="col">
                <div class="card">
                    <h3>Общи данни</h3>
                    <p class="text-muted">Тук ще добавиш KPI, статистики и общ преглед.</p>
                </div>
            </div>

            <div class="col">
                <div class="card">
                    <h3>Потребители</h3>
                    <p class="text-muted">Тук ще добавиш списък с потребители, роли и статути.</p>
                </div>
            </div>
        </div>
    </div>
@endsection