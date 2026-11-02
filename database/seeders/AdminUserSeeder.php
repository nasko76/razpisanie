<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'teacher']);
        Role::firstOrCreate(['name' => 'student']);
        Role::firstOrCreate(['name' => 'dean']);
        Role::firstOrCreate(['name' => 'department_head']);

        $user = User::firstOrCreate(
            ['email' => 'admin@razpisanie.local'],
            [
                'name' => 'Admin',
                'password' => bcrypt('admin123'),
            ]
        );

        $user->assignRole('admin');
    }
}