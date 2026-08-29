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
        Role::firstOrCreate(['nombre' => 'Administrador']);
        Role::firstOrCreate(['nombre' => 'Gerencia']);
        Role::firstOrCreate(['nombre' => 'Contabilidad']);
        Role::firstOrCreate(['nombre' => 'Asesor Financiero']);
        Role::firstOrCreate(['nombre' => 'Gestor de Cobranza']);

        User::updateOrCreate(
            ['email' => 'admin@agc.com'],
            [
                'name' => 'Administrador',
                'password' => '4gcGl0b4l/?', // password
                'email_verified_at' => now(),
                'role_id' => $adminRole->id,
            ]
        );
    }
}
