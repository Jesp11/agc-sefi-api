<?php

namespace App\Services;

use App\Models\AhorroPersonal;
use App\Models\AhorroPersonalMovimiento;
use App\Models\CicloHistorial;
use App\Models\Credito;
use App\Models\DocumentoCredito;
use App\Models\IndicadorOperativoEvento;
use App\Models\MovimientoCaja;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Builds and applies the deliberately narrow reversal for a credit entered by
 * mistake.  It never infers a relationship from amount/date when deleting.
 */
class CreditoEliminacionService
{
    public function __construct(private FlujoCajaService $flujoCajaService) {}

    public function preview(Credito $credito): array
    {
        $credito->loadMissing(['cliente', 'grupo']);
        $folio = (int) $credito->num_prog;
        $pagos = $credito->pagos()->orderBy('id')->get();
        $pagoIds = $pagos->pluck('id')->map(fn ($id) => (int) $id)->all();

        $movimientosPorPago = empty($pagoIds)
            ? collect()
            : MovimientoCaja::whereIn('pago_id', $pagoIds)->orderBy('id')->get();
        $desembolsos = MovimientoCaja::where('referencia', "DESEMBOLSO-{$folio}")
            ->orderBy('id')
            ->get();
        $idsYaVinculados = $movimientosPorPago->pluck('id')
            ->merge($desembolsos->pluck('id'))
            ->unique()
            ->all();
        // A num_prog is an explicit operator-level link.  These can be manual
        // movements, as well as legacy automatic records without a pago_id.
        $movimientosManuales = MovimientoCaja::where('num_prog', $folio)
            ->when(! empty($idsYaVinculados), fn ($q) => $q->whereNotIn('id', $idsYaVinculados))
            ->orderBy('id')
            ->get();

        $ahorros = $this->ahorrosDesdePagos($pagoIds, $folio);
        $documentos = DocumentoCredito::where('num_prog', $folio)->orderBy('id')->get();
        $ciclos = CicloHistorial::where('num_prog', $folio)->orderBy('id')->get();
        $indicadores = IndicadorOperativoEvento::where(function ($query) use ($folio) {
            $query->where('num_prog', $folio)->orWhere('num_prog_relacionado', $folio);
        })->orderBy('id')->get();

        $posibles = $this->coincidenciasPosibles($credito);
        $bloqueado = $this->participaEnRenovacion($credito);

        $impactos = [
            'pagos' => $pagos->map(fn ($pago) => [
                'id' => $pago->id,
                'fecha' => $pago->fecha?->toDateString(),
                'tipo' => $pago->tipo,
                'monto' => (float) $pago->monto,
                'ahorro_personal_monto' => (float) ($pago->ahorro_personal_monto ?? 0),
                'actualizado_en' => $pago->updated_at?->toISOString(),
            ])->values()->all(),
            'ingresos_caja' => $this->resumenMovimientos($movimientosPorPago),
            'egresos_desembolso' => $this->resumenMovimientos($desembolsos),
            'movimientos_manuales' => $this->resumenMovimientos($movimientosManuales),
            'ahorros_personal' => $ahorros->map(fn ($movimiento) => [
                'id' => $movimiento->id,
                'ahorro_personal_id' => $movimiento->ahorro_personal_id,
                'fecha' => $movimiento->fecha?->toDateString(),
                'tipo' => $movimiento->tipo,
                'monto' => (float) $movimiento->monto,
                'notas' => $movimiento->notas,
                'actualizado_en' => $movimiento->updated_at?->toISOString(),
            ])->values()->all(),
            'documentos' => $documentos->map(fn ($documento) => [
                'id' => $documento->id,
                'tipo' => $documento->tipo,
                'nombre_archivo' => $documento->nombre_archivo,
                'ruta' => $documento->ruta,
                'actualizado_en' => $documento->updated_at?->toISOString(),
            ])->values()->all(),
            'ciclos_historial' => $ciclos->map(fn ($ciclo) => [
                'id' => $ciclo->id,
                'ciclo' => $ciclo->ciclo,
                'resultado' => $ciclo->resultado,
                'fecha_inicio' => $ciclo->fecha_inicio?->toDateString(),
                'fecha_fin' => $ciclo->fecha_fin?->toDateString(),
                'actualizado_en' => $ciclo->updated_at?->toISOString(),
            ])->values()->all(),
            'indicadores_operativos' => $indicadores->map(fn ($indicador) => [
                'id' => $indicador->id,
                'fecha' => $indicador->fecha?->toDateString(),
                'tipo' => $indicador->tipo,
                'monto' => (float) $indicador->monto,
                'origen' => $indicador->origen,
                'actualizado_en' => $indicador->updated_at?->toISOString(),
            ])->values()->all(),
        ];

        $preview = [
            'bloqueado' => $bloqueado,
            'motivo_bloqueo' => $bloqueado
                ? 'Este crédito participa en una renovación. Requiere una reversión específica para restaurar el crédito anterior.'
                : null,
            'credito' => [
                'num_prog' => $folio,
                'tipo_credito' => $credito->tipo_credito,
                'beneficiario' => $credito->cliente?->nombre_completo ?? $credito->grupo?->nombre_grupo,
                'fecha_desembolso' => $this->fecha($credito->fecha_otorgacion),
                'monto_otorgado' => (float) $credito->monto_otorgado,
                'comision_apertura' => (float) ($credito->comision_apertura ?? 0),
                'monto_neto_desembolsado' => max(0, (float) $credito->monto_otorgado - (float) ($credito->comision_apertura ?? 0)),
                'origen' => $credito->id_cliente ? 'Cliente' : 'Grupo',
                'actualizado_en' => $credito->updated_at?->toISOString(),
            ],
            'impactos' => $impactos,
            'coincidencias_posibles' => $this->resumenMovimientos($posibles),
        ];
        $preview['huella'] = $this->huella($preview);

        return $preview;
    }

    /** @return array{preview: array, documentos_eliminados: int} */
    public function eliminar(int $folio, string $huellaEsperada): array
    {
        $resultado = DB::transaction(function () use ($folio, $huellaEsperada) {
            $credito = Credito::whereKey($folio)->lockForUpdate()->firstOrFail();
            $preview = $this->preview($credito);

            if ($preview['bloqueado']) {
                throw new CreditoEliminacionBloqueadaException($preview['motivo_bloqueo']);
            }
            if (! hash_equals($preview['huella'], $huellaEsperada)) {
                throw new CreditoEliminacionDesactualizadaException('Los impactos cambiaron desde la previsualización. Revísalos nuevamente antes de eliminar.');
            }

            $impactos = $preview['impactos'];
            $fechaMasAntigua = collect([
                ...$impactos['ingresos_caja'],
                ...$impactos['egresos_desembolso'],
                ...$impactos['movimientos_manuales'],
            ])->pluck('fecha')->filter()->min();

            $idsAhorro = collect($impactos['ahorros_personal'])->pluck('id')->all();
            $ahorros = empty($idsAhorro)
                ? collect()
                : AhorroPersonalMovimiento::whereIn('id', $idsAhorro)->lockForUpdate()->get();
            foreach ($ahorros->groupBy('ahorro_personal_id') as $ahorroPersonalId => $movimientos) {
                $monto = (float) $movimientos->sum('monto');
                // The movement is the source of truth for this reversal; use an
                // atomic decrement so a later withdrawal is not overwritten.
                AhorroPersonal::whereKey($ahorroPersonalId)->decrement('saldo', $monto);
            }
            if ($idsAhorro) {
                AhorroPersonalMovimiento::whereIn('id', $idsAhorro)->delete();
            }

            $movimientoIds = collect([
                ...$impactos['ingresos_caja'],
                ...$impactos['egresos_desembolso'],
                ...$impactos['movimientos_manuales'],
            ])->pluck('id')->unique()->values()->all();
            if ($movimientoIds) {
                MovimientoCaja::whereIn('id', $movimientoIds)->delete();
            }

            $rutasDocumentos = collect($impactos['documentos'])->pluck('ruta')->filter()->all();
            DocumentoCredito::where('num_prog', $folio)->delete();
            CicloHistorial::where('num_prog', $folio)->delete();
            IndicadorOperativoEvento::where('num_prog', $folio)
                ->orWhere('num_prog_relacionado', $folio)
                ->delete();
            // Payments are intentionally removed before the credit even though
            // the FK is cascade: this makes the reversal explicit and portable.
            $credito->pagos()->delete();
            $credito->delete();

            if ($fechaMasAntigua) {
                $this->flujoCajaService->recalcularSaldosDesde($fechaMasAntigua);
            }

            return [
                'preview' => $preview,
                'rutas_documentos' => $rutasDocumentos,
            ];
        });

        foreach ($resultado['rutas_documentos'] as $ruta) {
            Storage::disk('public')->delete($ruta);
        }

        return [
            'preview' => $resultado['preview'],
            'documentos_eliminados' => count($resultado['rutas_documentos']),
        ];
    }

    private function participaEnRenovacion(Credito $credito): bool
    {
        $folio = $credito->num_prog;

        return $credito->refinanciamientos()->exists()
            || $credito->refinanciamientosComoAnterior()->exists()
            || $credito->credito_padre_id !== null
            || Credito::where('credito_padre_id', $folio)->exists();
    }

    private function ahorrosDesdePagos(array $pagoIds, int $folio): Collection
    {
        if (empty($pagoIds)) {
            return collect();
        }

        return AhorroPersonalMovimiento::where(function ($query) use ($pagoIds, $folio) {
            foreach ($pagoIds as $pagoId) {
                $query->orWhere('notas', 'like', "%abono #{$pagoId} / crédito #{$folio}%");
            }
        })->orderBy('id')->get();
    }

    private function coincidenciasPosibles(Credito $credito): Collection
    {
        $fecha = $this->fecha($credito->fecha_otorgacion);
        if (! $fecha) {
            return collect();
        }

        $montos = array_unique(array_filter([
            round((float) $credito->monto_otorgado, 2),
            round(max(0, (float) $credito->monto_otorgado - (float) ($credito->comision_apertura ?? 0)), 2),
        ], fn ($monto) => $monto > 0));
        if (empty($montos)) {
            return collect();
        }

        return MovimientoCaja::whereDate('fecha', $fecha)
            ->whereNull('num_prog')
            ->whereNull('pago_id')
            ->orderBy('id')
            ->get()
            ->filter(fn (MovimientoCaja $movimiento) => collect($montos)
                ->contains(fn ($monto) => abs((float) $movimiento->monto - $monto) < 0.005))
            ->values();
    }

    private function resumenMovimientos(Collection $movimientos): array
    {
        return $movimientos->map(fn (MovimientoCaja $movimiento) => [
            'id' => $movimiento->id,
            'fecha' => $movimiento->fecha?->toDateString(),
            'tipo' => $movimiento->tipo,
            'monto' => (float) $movimiento->monto,
            'motivo' => $movimiento->motivo,
            'categoria' => $movimiento->categoria,
            'referencia' => $movimiento->referencia,
            'actualizado_en' => $movimiento->updated_at?->toISOString(),
        ])->values()->all();
    }

    private function huella(array $preview): string
    {
        unset($preview['huella']);
        return hash('sha256', json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function fecha(mixed $fecha): ?string
    {
        if (! $fecha) {
            return null;
        }

        return is_string($fecha) ? substr($fecha, 0, 10) : $fecha->toDateString();
    }
}
