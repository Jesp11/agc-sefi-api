<?php

namespace Database\Seeders;

use App\Models\AhorroPersonal;
use App\Models\Asesor;
use Illuminate\Database\Seeder;

class AhorrosPersonalSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Asesor::all() as $asesor) {
            AhorroPersonal::firstOrCreate(['asesor_id' => $asesor->id], ['saldo' => 0]);
        }
    }
}
