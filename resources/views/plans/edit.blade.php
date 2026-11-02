@extends('layouts.app')

@section('content')
<div class="container">
    <h2>Редакция на план</h2>

    <form method="POST" action="{{ route('plans.update', $plan) }}">
        @csrf
        @method('PUT')

        <div class="mb-3">
            <label class="form-label">Заглавие</label>
            <input type="text" name="title" class="form-control" value="{{ old('title', $plan->title) }}" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Ден</label>
            <select name="day_of_week" class="form-control" required>
                @foreach([1,2,3,4,5,6,7] as $day)
                    <option value="{{ $day }}" {{ old('day_of_week', $plan->day_of_week) == $day ? 'selected' : '' }}>
                        @switch($day)
                            @case(1) Понеделник @break
                            @case(2) Вторник @break
                            @case(3) Сряда @break
                            @case(4) Четвъртък @break
                            @case(5) Петък @break
                            @case(6) Събота @break
                            @case(7) Неделя @break
                        @endswitch
                    </option>
                @endforeach
            </select>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Начало</label>
                <input type="time" name="start_time" class="form-control" value="{{ old('start_time', $plan->start_time) }}" required>
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">Край</label>
                <input type="time" name="end_time" class="form-control" value="{{ old('end_time', $plan->end_time) }}" required>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Стая</label>
            <input type="text" name="room" class="form-control" value="{{ old('room', $plan->room) }}">
        </div>

        <div class="mb-3">
            <label class="form-label">Преподавател</label>
            <input type="text" name="teacher_name" class="form-control" value="{{ old('teacher_name', $plan->teacher_name) }}">
        </div>

        <div class="mb-3">
            <label class="form-label">Група</label>
            <input type="text" name="group_name" class="form-control" value="{{ old('group_name', $plan->group_name) }}">
        </div>

        <div class="mb-3">
            <label class="form-label">Бележки</label>
            <textarea name="notes" class="form-control" rows="4">{{ old('notes', $plan->notes) }}</textarea>
        </div>

        <div class="mb-3">
            <label class="form-label">Статус</label>
            <select name="status" class="form-control">
                <option value="active" {{ old('status', $plan->status) == 'active' ? 'selected' : '' }}>Активен</option>
                <option value="draft" {{ old('status', $plan->status) == 'draft' ? 'selected' : '' }}>Чернова</option>
                <option value="archived" {{ old('status', $plan->status) == 'archived' ? 'selected' : '' }}>Архивиран</option>
            </select>
        </div>

        <button type="submit" class="btn btn-success">Запази промени</button>
        <a href="{{ route('plans.index') }}" class="btn btn-secondary">Назад</a>
    </form>
</div>
@endsection