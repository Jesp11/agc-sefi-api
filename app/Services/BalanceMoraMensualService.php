<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class BalanceMoraMensualService
{
    public function serieAnual(
        int $anio,
        ?string $mesEnCurso = null,
        ?float $moraActivaEnCurso = null,
        ?float $moraMuertaEnCurso = null
    ): array {
        $registros = DB::table('balance_mora_mensual')
            ->whereBetween('mes', [sprintf('%04d-01', $anio), sprintf('%04d-12', $anio)])
            ->get()
            ->keyBy('mes');

        $serie = [];
        for ($numeroMes = 1; $numeroMes <= 12; $numeroMes++) {
            $mes = sprintf('%04d-%02d', $anio, $numeroMes);
            $registro = $registros->get($mes);

            $serie[] = [
                'mes' => $mes,
                'mora_activa' => $registro !== null
                    ? (float) $registro->mora_activa
                    : ($mes === $mesEnCurso ? $moraActivaEnCurso : null),
                'mora_muerta' => $registro !== null
                    ? (float) $registro->mora_muerta
                    : ($mes === $mesEnCurso ? $moraMuertaEnCurso : null),
                'origen' => $registro?->origen ?? ($mes === $mesEnCurso ? 'en_curso' : null),
            ];
        }

        return $serie;
    }

    public function registrarCierre(string $mes, float $moraActiva, float $moraMuerta): void
    {
        DB::table('balance_mora_mensual')->insertOrIgnore([
            'mes' => $mes,
            'mora_activa' => $moraActiva,
            'mora_muerta' => $moraMuerta,
            'origen' => 'cierre',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
