<?php

namespace App\Services;

use App\Models\CicloHistorial;
use App\Models\Credito;
use App\Models\Cliente;
use App\Models\Grupo;
use Illuminate\Support\Facades\DB;

class CicloService
{
    public function calcularCiclo(?string $idCliente = null, ?int $idGrupo = null): int
    {
        $query = Credito::query();

        if ($idCliente) {
            $query->where('id_cliente', $idCliente);
        } elseif ($idGrupo) {
            $query->where('id_grupo', $idGrupo);
        }

        $maxCiclo = $query->max('ciclo');

        return ($maxCiclo ?? -1) + 1;
    }

    public function registrarInicio(Credito $credito): CicloHistorial
    {
        return CicloHistorial::create([
            'id_cliente' => $credito->id_cliente,
            'id_grupo' => $credito->id_grupo,
            'ciclo' => $credito->ciclo,
            'num_prog' => $credito->num_prog,
            'fecha_inicio' => $credito->fecha_otorgacion,
            'resultado' => 'Activo',
        ]);
    }

    public function cerrarCiclo(Credito $credito, string $resultado): void
    {
        CicloHistorial::where('num_prog', $credito->num_prog)
            ->where('resultado', 'Activo')
            ->update([
                'fecha_fin' => now()->toDateString(),
                'resultado' => $resultado,
            ]);
    }
}
