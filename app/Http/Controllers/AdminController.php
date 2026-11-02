<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class AdminController extends Controller
{
    public function users()
    {
        $this->authorize('manage users');

        $users = User::with('roles', 'permissions')->get();

        $roles = Role::all();
        $permissions = Permission::all();

        return view('admin.users', compact('users', 'roles', 'permissions'));
    }

    public function updateUserAccess(Request $request, User $user)
    {
        $this->authorize('manage users');

        $request->validate([
            'role' => ['nullable', 'string', 'exists:roles,name'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        if ($request->filled('role')) {
            $user->syncRoles($request->role);
        }

        $user->syncPermissions($request->permissions ?? []);

        return redirect()->route('admin.users')->with('success', 'Потребителят е обновен успешно.');
    }
}