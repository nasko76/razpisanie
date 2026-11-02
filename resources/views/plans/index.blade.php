@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Планове</h2>

        @can('create', App\Models\Plan::class)
            <a href="{{ route('plans.create') }}" class="btn btn-primary">Добави план</a>
        @endcan
    </div>

    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>Заглавие</th>
                <th>Ден</th>
                <th>Начало</th>
                <th>Край</th>
                <th>Стая</th>
                <th>Преподавател</th>
                <th>Група</th>
                <th>Статус</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            @forelse($plans as $plan)
                <tr>
                    <td>{{ $plan->title }}</td>
                    <td>
                        @switch($plan->day_of_week)
                            @case(1) Понеделник @break
                            @case(2) Вторник @break
                            @case(3) Сряда @break
                            @case(4) Четвъртък @break
                            @case(5) Петък @break
                            @case(6) Събота @break
                            @case(7) Неделя @break
                            @default -
                        @endswitch
                    </td>
                    <td>{{ $plan->start_time }}</td>
                    <td>{{ $plan->end_time }}</td>
                    <td>{{ $plan->room ?? '-' }}</td>
                    <td>{{ $plan->teacher_name ?? '-' }}</td>
                    <td>{{ $plan->group_name ?? '-' }}</td>
                    <td>{{ $plan->status }}</td>
                    <td>
                        @can('update', $plan)
                            <a href="{{ route('plans.edit', $plan) }}" class="btn btn-sm btn-warning">Редактирай</a>
                        @endcan

                        @can('delete', $plan)
                            <form action="{{ route('plans.destroy', $plan) }}" method="POST" class="d-inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Сигурен ли си?')">
                                    Изтрий
                                </button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center">Няма налични планове.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection