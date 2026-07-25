<?php

namespace App\Services;

use App\Models\AhorroEmpleado;
use App\Models\Aportacion;
use App\Models\Credito;
use App\Models\GastoOperativo;
use App\Models\MovimientoCapital;
use App\Services\FlujoCajaService;
use Illuminate\Support\Facades\DB;

class CapitalService
{
    public function registrarAportacion(int $inversionistaId, array $data): Aportacion
    {
        return DB::transaction(function () use ($inversionistaId, $data) {
            $aportacion = Aportacion::create([
                'inversionista_id' => $inversionistaId,
                'monto' => $data['monto'],
                'fecha' => $data['fecha'],
                'tipo' => $data['tipo'] ?? 'Aportacion',
                'notas' => $data['notas'] ?? null,
                'registrado_por' => auth()->id(),
            ]);

            MovimientoCapital::create([
                'tipo' => $data['tipo'] === 'Retiro' ? 'Retiro' : 'Aportacion',
                'monto' => $data['tipo'] === 'Retiro' ? -abs($data['monto']) : abs($data['monto']),
                'referencia' => "INV-{$aportacion->id}",
                'fecha' => $data['fecha'],
                'descripcion' => "Aportación inversionista #{$inversionistaId}",
                'registrado_por' => auth()->id(),
            ]);

            return $aportacion;
        });
    }

    public function registrarGasto(array $data): GastoOperativo
    {
        return DB::transaction(function () use ($data) {
            $gasto = GastoOperativo::create([
                'concepto' => $data['concepto'],
                'monto' => $data['monto'],
                'fecha' => $data['fecha'],
                'categoria' => $data['categoria'] ?? null,
                'catalogo_gasto_id' => $data['catalogo_gasto_id'] ?? null,
                'registrado_por' => auth()->id(),
            ]);

            MovimientoCapital::create([
                'tipo' => 'Gasto',
                'monto' => -abs($data['monto']),
                'referencia' => "GASTO-{$gasto->id}",
                'fecha' => $data['fecha'],
                'descripcion' => $data['concepto'],
                'registrado_por' => auth()->id(),
            ]);

            app(FlujoCajaService::class)->registrarDesdeGasto($gasto);

            return $gasto;
        });
    }

    public function capitalPasivo(): array
    {
        $aportaciones = Aportacion::where('tipo', 'Aportacion')->sum('monto')
            - Aportacion::where('tipo', 'Retiro')->sum('monto');
        $colocado = Credito::sum('monto_otorgado');
        $gastos = GastoOperativo::sum('monto');
        $nomina = MovimientoCapital::where('tipo', 'Nomina')->sum(DB::raw('ABS(monto)'));

        return [
            'total_aportaciones' => round((float) $aportaciones, 2),
            'total_colocado' => round((float) $colocado, 2),
            'total_gastos' => round((float) $gastos, 2),
            'total_nomina' => round((float) $nomina, 2),
            'capital_pasivo' => round((float) $aportaciones - $colocado - $gastos - $nomina, 2),
            'movimientos' => MovimientoCapital::orderByDesc('fecha')->limit(50)->get(),
        ];
    }

    public function totalAhorros(): array
    {
        return [
            'total_saldo' => round((float) AhorroEmpleado::sum('saldo'), 2),
            'empleados' => AhorroEmpleado::with('empleado')->get(),
        ];
    }
}
