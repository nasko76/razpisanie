@extends('layouts.app')

@section('content')
<div class="container">
    <h2>Нов план</h2>

    <form method="POST" action="{{ route('plans.store') }}">
        @csrf

        <div class="mb-3">
            <label class="form-label">Заглавие</label>
            <input type="text" name="title" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Ден</label>
            <select name="day_of_week" class="form-control" required>
                <option value="1">Понеделник</option>
                <option value="2">Вторник</option>
                <option value="3">Сряда</option>
                <option value="4">Четвъртък</option>
                <option value="5">Петък</option>
                <option value="6">Събота</option>
                <option value="7">Неделя</option>
            </select>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Начало</label>
                <input type="time" name="start_time" class="form-control" required>
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">Край</label>
                <input type="time" name="end_time" class="form-control" required>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Стая</label>
            <input type="text" name="room" class="form-control">
        </div>

        <div class="mb-3">
            <label class="form-label">Преподавател</label>
            <input type="text" name="teacher_name" class="form-control">
        </div>

        <div class="mb-3">
            <label class="form-label">Група</label>
            <input type="text" name="group_name" class="form-control">
        </div>

        <div class="mb-3">
            <label class="form-label">Бележки</label>
            <textarea name="notes" class="form-control" rows="4"></textarea>
        </div>

        <div class="mb-3">
            <label class="form-label">Статус</label>
            <select name="status" class="form-control">
                <option value="active">Активен</option>
                <option value="draft">Чернова</option>
                <option value="archived">Архивиран</option>
            </select>
        </div>

        <button type="submit" class="btn btn-success">Запази</button>
        <a href="{{ route('plans.index') }}" class="btn btn-secondary">Назад</a>
    </form>
</div>
@endsection