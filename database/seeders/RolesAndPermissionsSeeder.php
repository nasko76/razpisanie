<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view plans',
            'create plans',
            'edit plans',
            'delete plans',
            'manage users',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $admin = Role::firstOrCreate(['name' => 'admin']);
        $manager = Role::firstOrCreate(['name' => 'manager']);
        $user = Role::firstOrCreate(['name' => 'user']);

        $admin->syncPermissions([
            'view plans',
            'create plans',
            'edit plans',
            'delete plans',
            'manage users',
        ]);

        $manager->syncPermissions([
            'view plans',
            'create plans',
            'edit plans',
        ]);

        $user->syncPermissions([
            'view plans',
        ]);
    }
}