<?php

namespace Database\Seeders;

use App\Models\AhorroSocio;
use App\Models\Socio;
use Illuminate\Database\Seeder;

class SociosSeeder extends Seeder
{
    public function run(): void
    {
        $socios = [
            ['nombre' => 'SOCIO EJEMPLO 1', 'codigo' => 'SOC-001'],
            ['nombre' => 'SOCIO EJEMPLO 2', 'codigo' => 'SOC-002'],
        ];

        foreach ($socios as $data) {
            $socio = Socio::firstOrCreate(['codigo' => $data['codigo']], $data);
            AhorroSocio::firstOrCreate(['socio_id' => $socio->id], ['saldo' => 0]);
        }
    }
}
