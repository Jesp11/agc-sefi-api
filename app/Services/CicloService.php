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
            'fecha_consulta' => now()->toDateString(),
            'resultado' => 'Activo',
            'snapshot' => $this->buildSnapshot($credito, 'Activo'),
        ]);
    }

    public function cerrarCiclo(Credito $credito, string $resultado): void
    {
        CicloHistorial::where('num_prog', $credito->num_prog)
            ->where('resultado', 'Activo')
            ->update([
                'fecha_fin' => now()->toDateString(),
                'fecha_consulta' => now()->toDateString(),
                'resultado' => $resultado,
                'snapshot' => $this->buildSnapshot($credito, $resultado),
            ]);
    }

    private function buildSnapshot(Credito $credito, string $resultado): array
    {
        $credito->loadMissing(['cliente', 'grupo', 'asesor', 'pagos']);

        return [
            'resultado' => $resultado,
            'estado' => $credito->estado,
            'saldo_pendiente' => round((float) ($credito->saldo_pendiente ?? 0), 2),
            'monto_otorgado' => round((float) ($credito->monto_otorgado ?? 0), 2),
            'total' => round((float) ($credito->total ?? 0), 2),
            'plazos' => (int) ($credito->plazos ?? 0),
            'valor_ficha' => round((float) ($credito->valor_ficha ?? 0), 2),
            'dias_mora_cache' => (int) ($credito->dias_mora_cache ?? 0),
            'ciclo_inicio_mora' => $credito->ciclo_inicio_mora,
            'total_abonado' => round((float) $credito->pagos->where('tipo', 'Abono')->sum('monto'), 2),
            'cliente' => $credito->cliente?->only(['id_cliente', 'nombre_completo']),
            'grupo' => $credito->grupo?->only(['id', 'nombre_grupo']),
            'asesor' => $credito->asesor?->only(['id', 'id_asesor', 'nombre_asesor']),
        ];
    }
}
