@extends('layouts.app')

@section('content')
<div class="container">
    <h2>Управление на потребители</h2>

    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Име</th>
                <th>Email</th>
                <th>Роли</th>
                <th>Permissions</th>
                <th>Действие</th>
            </tr>
        </thead>
        <tbody>
            @foreach($users as $user)
                <tr>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td>
                        @forelse($user->roles as $role)
                            <span class="badge bg-primary">{{ $role->name }}</span>
                        @empty
                            <span class="text-muted">Няма роля</span>
                        @endforelse
                    </td>
                    <td>
                        @forelse($user->permissions as $permission)
                            <span class="badge bg-secondary">{{ $permission->name }}</span>
                        @empty
                            <span class="text-muted">Няма permissions</span>
                        @endforelse
                    </td>
                    <td>
                        <form method="POST" action="{{ route('admin.updateUserAccess', $user) }}">
                            @csrf

                            <div class="mb-2">
                                <label class="form-label">Роля</label>
                                <select name="role" class="form-select">
                                    <option value="">- no change -</option>
                                    @foreach($roles as $role)
                                        <option value="{{ $role->name }}" {{ $user->hasRole($role->name) ? 'selected' : '' }}>
                                            {{ $role->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="mb-2">
                                <label class="form-label">Permissions</label>
                                <div class="d-flex flex-wrap gap-2">
                                    @foreach($permissions as $permission)
                                        <label class="form-check">
                                            <input
                                                type="checkbox"
                                                name="permissions[]"
                                                value="{{ $permission->name }}"
                                                {{ $user->hasPermissionTo($permission->name) ? 'checked' : '' }}
                                            >
                                            <span>{{ $permission->name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <button type="submit" class="btn btn-success btn-sm">Запази</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection