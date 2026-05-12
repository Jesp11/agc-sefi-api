<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@sefi.com'],
            [
                'name' => 'Administrador SEFI',
                'password' => Hash::make('admin123456'),
                'email_verified_at' => now(),
            ]
        );
    }
}
