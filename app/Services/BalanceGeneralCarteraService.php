<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class BalanceGeneralCarteraService
{
    public function serieAnual(
        int $anio,
        ?string $mesEnCurso = null,
        ?float $valorBrutoEnCurso = null,
        ?float $valorNetoEnCurso = null
    ): array {
        $registros = DB::table('balance_general_cartera_mensual')
            ->whereBetween('mes', [sprintf('%04d-01', $anio), sprintf('%04d-12', $anio)])
            ->get()
            ->keyBy('mes');

        $serie = [];
        for ($numeroMes = 1; $numeroMes <= 12; $numeroMes++) {
            $mes = sprintf('%04d-%02d', $anio, $numeroMes);
            $registro = $registros->get($mes);

            $serie[] = [
                'mes' => $mes,
                'valor_bruto' => $registro !== null
                    ? (float) $registro->valor_bruto
                    : ($mes === $mesEnCurso ? $valorBrutoEnCurso : null),
                'valor_neto' => $registro !== null
                    ? (float) $registro->valor_neto
                    : ($mes === $mesEnCurso ? $valorNetoEnCurso : null),
                'origen' => $registro?->origen ?? ($mes === $mesEnCurso ? 'en_curso' : null),
            ];
        }

        return $serie;
    }

    public function registrarCierre(string $mes, float $valorBruto, float $valorNeto): void
    {
        DB::table('balance_general_cartera_mensual')->insertOrIgnore([
            'mes' => $mes,
            'valor_bruto' => $valorBruto,
            'valor_neto' => $valorNeto,
            'origen' => 'cierre',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
