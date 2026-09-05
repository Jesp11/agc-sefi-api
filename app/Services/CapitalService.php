<?php

namespace App\Services;

use App\Models\AhorroEmpleado;
use App\Models\Aportacion;
use App\Models\Credito;
use App\Models\GastoOperativo;
use App\Models\MovimientoCapital;
use App\Models\MovimientoCaja;
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

    public function registrarPagoRendimiento(int $inversionistaId, array $data): Aportacion
    {
        return DB::transaction(function () use ($inversionistaId, $data) {
            $inversionista = \App\Models\Inversionista::findOrFail($inversionistaId);
            $monto = abs((float) $data['monto']);
            $fecha = $data['fecha'];
            $cuenta = $data['cuenta'] ?? 'Efectivo';
            $concepto = $data['concepto'] ?? "PAGO DE RENDIMIENTO — {$inversionista->nombre}";
            $notas = $data['notas'] ?? null;

            $aportacion = Aportacion::create([
                'inversionista_id' => $inversionistaId,
                'monto' => $monto,
                'fecha' => $fecha,
                'tipo' => 'Rendimiento',
                'notas' => $notas,
                'registrado_por' => auth()->id(),
            ]);

            MovimientoCapital::create([
                'tipo' => 'Gasto',
                'monto' => -$monto,
                'referencia' => "REND-INV-{$aportacion->id}",
                'fecha' => $fecha,
                'descripcion' => $concepto,
                'registrado_por' => auth()->id(),
            ]);

            // Registrar en Flujo de Caja como Egreso en categoría Rendimiento
            app(FlujoCajaService::class)->registrar([
                'fecha' => $fecha,
                'motivo' => $concepto,
                'tipo' => 'Egreso',
                'monto' => $monto,
                'categoria' => 'Rendimiento',
                'cuenta' => $cuenta,
                'referencia' => "REND-INV-{$aportacion->id}",
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
                'cuenta' => $data['cuenta'] ?? null,
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

    public function actualizarGasto(GastoOperativo $gasto, array $data): GastoOperativo
    {
        return DB::transaction(function () use ($gasto, $data) {
            $fechaAnterior = $gasto->fecha?->toDateString() ?? $data['fecha'];

            $gasto->update([
                'concepto' => $data['concepto'],
                'monto' => $data['monto'],
                'fecha' => $data['fecha'],
                'categoria' => $data['categoria'] ?? null,
                'cuenta' => $data['cuenta'] ?? null,
                'catalogo_gasto_id' => $data['catalogo_gasto_id'] ?? null,
            ]);

            MovimientoCapital::where('referencia', "GASTO-{$gasto->id}")->update([
                'monto' => -abs((float) $gasto->monto),
                'fecha' => $gasto->fecha,
                'descripcion' => $gasto->concepto,
            ]);

            $movimientoCaja = MovimientoCaja::where('referencia', "GASTO-{$gasto->id}")->first();
            if ($movimientoCaja) {
                $movimientoCaja->update([
                    'fecha' => $gasto->fecha,
                    'motivo' => $gasto->concepto,
                    'monto' => $gasto->monto,
                    'categoria' => $gasto->categoria ?: 'GastoOperativo',
                    'cuenta' => $gasto->cuenta,
                ]);

                app(FlujoCajaService::class)->recalcularSaldosDesde(min(
                    $fechaAnterior,
                    $gasto->fecha->toDateString(),
                ));
            } else {
                app(FlujoCajaService::class)->registrarDesdeGasto($gasto);
            }

            return $gasto->fresh();
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
