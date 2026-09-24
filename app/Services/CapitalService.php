<?php

namespace App\Services;

use App\Models\AhorroEmpleado;
use App\Models\Aportacion;
use App\Models\Credito;
use App\Models\GastoOperativo;
use App\Models\Inversionista;
use App\Models\LiquidacionInversionista;
use App\Models\ReactivacionInversionista;
use App\Models\MovimientoCapital;
use App\Models\MovimientoCaja;
use App\Models\ConfirmacionMovimiento;
use App\Services\FlujoCajaService;
use Illuminate\Support\Facades\DB;

class CapitalService
{
    public function registrarAportacion(int $inversionistaId, array $data): Aportacion
    {
        return DB::transaction(function () use ($inversionistaId, $data) {
            $inversionista = Inversionista::lockForUpdate()->findOrFail($inversionistaId);
            if (! $inversionista->activo) {
                throw new \InvalidArgumentException('El inversionista está inactivo. Debes reactivarlo antes de registrar movimientos.');
            }
            $this->asegurarSinLiquidacionPendiente($inversionista);
            $tipo = $data['tipo'] ?? 'Aportacion';

            $aportacion = Aportacion::create([
                'inversionista_id' => $inversionistaId,
                'monto' => $data['monto'],
                'fecha' => $data['fecha'],
                'tipo' => $tipo,
                'notas' => $data['notas'] ?? null,
                'registrado_por' => auth()->id(),
            ]);

            MovimientoCapital::create([
                'tipo' => $tipo === 'Retiro' ? 'Retiro' : 'Aportacion',
                'monto' => $tipo === 'Retiro' ? -abs($data['monto']) : abs($data['monto']),
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
            $inversionista = Inversionista::lockForUpdate()->findOrFail($inversionistaId);
            if (! $inversionista->activo) {
                throw new \InvalidArgumentException('El inversionista está inactivo. Debes reactivarlo antes de registrar movimientos.');
            }
            $this->asegurarSinLiquidacionPendiente($inversionista);
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
            app(FlujoCajaService::class)->solicitarConfirmacionEgreso([
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

    public function solicitarLiquidacion(int $inversionistaId, array $data): LiquidacionInversionista
    {
        return DB::transaction(function () use ($inversionistaId, $data) {
            $inversionista = Inversionista::lockForUpdate()->findOrFail($inversionistaId);
            if (! $inversionista->activo) {
                throw new \InvalidArgumentException('El inversionista ya se encuentra inactivo.');
            }
            $this->asegurarSinLiquidacionPendiente($inversionista);

            $capital = $this->saldoCapital($inversionista->id);
            if ($capital <= 0) {
                throw new \InvalidArgumentException('El inversionista no tiene capital vigente para liquidar.');
            }

            $rendimiento = round(max(0, (float) ($data['rendimiento_final'] ?? 0)), 2);
            $liquidacion = LiquidacionInversionista::create([
                'inversionista_id' => $inversionista->id,
                'capital' => $capital,
                'rendimiento_final' => $rendimiento,
                'total' => round($capital + $rendimiento, 2),
                'fecha' => $data['fecha'],
                'cuenta' => $data['cuenta'],
                'notas' => $data['notas'] ?? null,
                'estado' => LiquidacionInversionista::PENDIENTE,
                'solicitado_por' => auth()->id(),
            ]);

            $confirmacion = app(FlujoCajaService::class)->solicitarConfirmacionEgreso([
                'fecha' => $data['fecha'],
                'motivo' => "LIQUIDACIÓN DE INVERSIONISTA — {$inversionista->nombre}",
                'tipo' => 'Egreso',
                'monto' => $liquidacion->total,
                'categoria' => 'LiquidacionInversionista',
                'cuenta' => $data['cuenta'],
                'referencia' => "LIQ-INV-{$liquidacion->id}",
            ]);

            $liquidacion->update(['confirmacion_movimiento_id' => $confirmacion->id]);

            return $liquidacion->fresh(['inversionista', 'confirmacionMovimiento', 'solicitadoPor']);
        });
    }

    public function ajustarCapitalInversionista(int $inversionistaId, array $data): Inversionista
    {
        return DB::transaction(function () use ($inversionistaId, $data) {
            $inversionista = Inversionista::lockForUpdate()->findOrFail($inversionistaId);
            if (! $inversionista->activo) {
                throw new \InvalidArgumentException('No se puede corregir el capital de un inversionista liquidado.');
            }
            $this->asegurarSinLiquidacionPendiente($inversionista);

            $aportadoActual = round((float) Aportacion::where('inversionista_id', $inversionista->id)
                ->where('tipo', 'Aportacion')->sum('monto'), 2);
            $retiradoActual = round((float) Aportacion::where('inversionista_id', $inversionista->id)
                ->where('tipo', 'Retiro')->sum('monto'), 2);
            $aportadoObjetivo = round((float) $data['total_aportaciones'], 2);
            $saldoObjetivo = round((float) $data['saldo_capital'], 2);
            if ($aportadoObjetivo < 0 || $saldoObjetivo < 0 || $saldoObjetivo > $aportadoObjetivo) {
                throw new \InvalidArgumentException('El capital vigente debe estar entre cero y el capital aportado total.');
            }
            $retiradoObjetivo = round($aportadoObjetivo - $saldoObjetivo, 2);
            $ajusteAportado = round($aportadoObjetivo - $aportadoActual, 2);
            $ajusteRetirado = round($retiradoObjetivo - $retiradoActual, 2);
            $motivo = trim((string) $data['motivo']);
            $fecha = $data['fecha'];

            if (abs($ajusteAportado) > 0.01) {
                $aporte = Aportacion::create([
                    'inversionista_id' => $inversionista->id,
                    'monto' => $ajusteAportado,
                    'fecha' => $fecha,
                    'tipo' => 'Aportacion',
                    'notas' => "Corrección manual de capital aportado — {$motivo}",
                    'registrado_por' => auth()->id(),
                ]);
                MovimientoCapital::create([
                    'tipo' => 'Otro',
                    'monto' => $ajusteAportado,
                    'referencia' => "AJUSTE-INV-{$aporte->id}-APORTADO",
                    'fecha' => $fecha,
                    'descripcion' => "Corrección de capital aportado de {$inversionista->nombre} — {$motivo}",
                    'registrado_por' => auth()->id(),
                ]);
            }

            if (abs($ajusteRetirado) > 0.01) {
                $retiro = Aportacion::create([
                    'inversionista_id' => $inversionista->id,
                    'monto' => $ajusteRetirado,
                    'fecha' => $fecha,
                    'tipo' => 'Retiro',
                    'notas' => "Corrección manual de capital retirado — {$motivo}",
                    'registrado_por' => auth()->id(),
                ]);
                MovimientoCapital::create([
                    'tipo' => 'Otro',
                    'monto' => -$ajusteRetirado,
                    'referencia' => "AJUSTE-INV-{$retiro->id}-RETIRADO",
                    'fecha' => $fecha,
                    'descripcion' => "Corrección de capital vigente de {$inversionista->nombre} — {$motivo}",
                    'registrado_por' => auth()->id(),
                ]);
            }

            return $inversionista->fresh('aportaciones');
        });
    }

    public function reactivarInversionista(int $inversionistaId, array $data): ReactivacionInversionista
    {
        return DB::transaction(function () use ($inversionistaId, $data) {
            $inversionista = Inversionista::lockForUpdate()->findOrFail($inversionistaId);
            if ($inversionista->activo) {
                throw new \InvalidArgumentException('El inversionista ya se encuentra activo.');
            }
            $this->asegurarSinLiquidacionPendiente($inversionista);
            if (abs($this->saldoCapital($inversionista->id)) > 0.01) {
                throw new \InvalidArgumentException('El inversionista debe tener capital vigente en cero para reactivarse.');
            }
            if (! LiquidacionInversionista::where('inversionista_id', $inversionista->id)
                ->where('estado', LiquidacionInversionista::CONFIRMADA)->exists()) {
                throw new \InvalidArgumentException('Solo se puede reactivar un inversionista con una liquidación confirmada.');
            }

            $reactivacion = ReactivacionInversionista::create([
                'inversionista_id' => $inversionista->id,
                'fecha' => $data['fecha'],
                'motivo' => trim((string) $data['motivo']),
                'realizado_por' => auth()->id(),
            ]);
            $inversionista->update(['activo' => true]);

            return $reactivacion->fresh(['inversionista', 'realizadoPor']);
        });
    }

    public function confirmarLiquidacion(
        LiquidacionInversionista $liquidacion,
        FlujoCajaService $flujoCajaService,
    ): ConfirmacionMovimiento {
        return DB::transaction(function () use ($liquidacion, $flujoCajaService) {
            $liquidacion = LiquidacionInversionista::lockForUpdate()->findOrFail($liquidacion->id);
            if ($liquidacion->estado !== LiquidacionInversionista::PENDIENTE) {
                throw new \InvalidArgumentException('Esta liquidación ya fue atendida.');
            }

            $inversionista = Inversionista::lockForUpdate()->findOrFail($liquidacion->inversionista_id);
            $capitalActual = $this->saldoCapital($inversionista->id);
            if (abs($capitalActual - (float) $liquidacion->capital) > 0.01) {
                throw new \InvalidArgumentException('El capital del inversionista cambió. Cancela esta solicitud y genera una nueva liquidación.');
            }

            $confirmacion = ConfirmacionMovimiento::lockForUpdate()->findOrFail($liquidacion->confirmacion_movimiento_id);
            $confirmacion = $flujoCajaService->confirmarEgresoPendiente($confirmacion);

            $retiro = Aportacion::create([
                'inversionista_id' => $inversionista->id,
                'monto' => $liquidacion->capital,
                'fecha' => $liquidacion->fecha,
                'tipo' => 'Retiro',
                'notas' => "Liquidación total #{$liquidacion->id} — retiro de capital",
                'registrado_por' => auth()->id(),
            ]);
            $movimientoRetiro = MovimientoCapital::create([
                'tipo' => 'Retiro',
                'monto' => -abs((float) $liquidacion->capital),
                'referencia' => "LIQ-INV-{$liquidacion->id}-CAPITAL",
                'fecha' => $liquidacion->fecha,
                'descripcion' => "Liquidación de capital — {$inversionista->nombre}",
                'registrado_por' => auth()->id(),
            ]);

            $rendimiento = null;
            $movimientoRendimiento = null;
            if ((float) $liquidacion->rendimiento_final > 0) {
                $rendimiento = Aportacion::create([
                    'inversionista_id' => $inversionista->id,
                    'monto' => $liquidacion->rendimiento_final,
                    'fecha' => $liquidacion->fecha,
                    'tipo' => 'Rendimiento',
                    'notas' => "Liquidación total #{$liquidacion->id} — rendimiento final",
                    'registrado_por' => auth()->id(),
                ]);
                $movimientoRendimiento = MovimientoCapital::create([
                    'tipo' => 'Gasto',
                    'monto' => -abs((float) $liquidacion->rendimiento_final),
                    'referencia' => "LIQ-INV-{$liquidacion->id}-RENDIMIENTO",
                    'fecha' => $liquidacion->fecha,
                    'descripcion' => "Rendimiento final de liquidación — {$inversionista->nombre}",
                    'registrado_por' => auth()->id(),
                ]);
            }

            $inversionista->update(['activo' => false]);
            $liquidacion->update([
                'estado' => LiquidacionInversionista::CONFIRMADA,
                'aportacion_retiro_id' => $retiro->id,
                'aportacion_rendimiento_id' => $rendimiento?->id,
                'movimiento_capital_retiro_id' => $movimientoRetiro->id,
                'movimiento_capital_rendimiento_id' => $movimientoRendimiento?->id,
                'confirmado_por' => auth()->id(),
                'confirmado_at' => now(),
            ]);

            return $confirmacion;
        });
    }

    public function cancelarLiquidacion(LiquidacionInversionista $liquidacion): LiquidacionInversionista
    {
        return DB::transaction(function () use ($liquidacion) {
            $liquidacion = LiquidacionInversionista::lockForUpdate()->findOrFail($liquidacion->id);
            if ($liquidacion->estado !== LiquidacionInversionista::PENDIENTE) {
                throw new \InvalidArgumentException('Esta liquidación ya fue atendida.');
            }

            $confirmacion = ConfirmacionMovimiento::lockForUpdate()->findOrFail($liquidacion->confirmacion_movimiento_id);
            if ($confirmacion->estado !== 'Pendiente') {
                throw new \InvalidArgumentException('El movimiento de caja ya fue atendido.');
            }

            $confirmacion->update([
                'estado' => 'Cancelado',
                'confirmado_por' => auth()->id(),
                'confirmado_at' => now(),
            ]);
            $liquidacion->update(['estado' => LiquidacionInversionista::CANCELADA]);

            return $liquidacion->fresh(['inversionista', 'confirmacionMovimiento']);
        });
    }

    private function saldoCapital(int $inversionistaId): float
    {
        $aportado = (float) Aportacion::where('inversionista_id', $inversionistaId)
            ->where('tipo', 'Aportacion')->sum('monto');
        $retirado = (float) Aportacion::where('inversionista_id', $inversionistaId)
            ->where('tipo', 'Retiro')->sum('monto');

        return round($aportado - $retirado, 2);
    }

    private function asegurarSinLiquidacionPendiente(Inversionista $inversionista): void
    {
        if (LiquidacionInversionista::where('inversionista_id', $inversionista->id)
            ->where('estado', LiquidacionInversionista::PENDIENTE)->exists()) {
            throw new \InvalidArgumentException('El inversionista tiene una liquidación pendiente de confirmar o cancelar.');
        }
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

    /** Elimina un gasto y los movimientos contables que se originaron con él. */
    public function eliminarGasto(GastoOperativo $gasto): void
    {
        DB::transaction(function () use ($gasto) {
            $fecha = $gasto->fecha->toDateString();
            $referencia = "GASTO-{$gasto->id}";

            MovimientoCaja::where('referencia', $referencia)->delete();
            ConfirmacionMovimiento::where('referencia', $referencia)->delete();
            MovimientoCapital::where('referencia', $referencia)->delete();
            $gasto->delete();

            app(FlujoCajaService::class)->recalcularSaldosDesde($fecha);
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
