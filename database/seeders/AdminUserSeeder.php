<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminRole = Role::firstOrCreate(['nombre' => 'admin']);
        Role::firstOrCreate(['nombre' => 'asesor']);

        User::updateOrCreate(
            ['email' => 'admin@sefi.com'],
            [
                'name' => 'Administrador SEFI',
                'password' => 'admin123456',
                'email_verified_at' => now(),
                'role_id' => $adminRole->id,
            ]
        );
    }
}
