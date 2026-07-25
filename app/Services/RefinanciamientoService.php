<?php

namespace App\Services;

use App\Models\Credito;
use App\Models\Refinanciamiento;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;

class RefinanciamientoService
{
    public function __construct(
        private MoraCalculationService $moraService,
        private CicloService $cicloService,
        private FlujoCajaService $flujoCajaService
    ) {}

    public function refinanciar(Credito $creditoAnterior, array $data): Credito
    {
        if (!in_array($creditoAnterior->estado, ['Activo', 'EnMora'], true)) {
            throw new InvalidArgumentException('Solo se pueden refinanciar créditos activos o en mora.');
        }

        return DB::transaction(function () use ($creditoAnterior, $data) {
            $mora = $this->moraService->calculate($creditoAnterior->load('pagos'));
            // Las multas no forman parte del saldo del préstamo (van al asesor).
            $saldoAnterior = (float) $mora['saldo_actual'];

            if ($saldoAnterior <= 0) {
                throw new InvalidArgumentException('El crédito no tiene saldo pendiente para refinanciar.');
            }

            $montoOtorgado = (float) $data['monto_otorgado'];
            if ($montoOtorgado <= $saldoAnterior) {
                throw new InvalidArgumentException('El nuevo monto debe ser mayor al saldo actual para extender el crédito.');
            }

            $deduccion = $saldoAnterior;
            $montoNeto = round($montoOtorgado - $deduccion, 2);
            $interes = (float) ($data['interes'] ?? 0);
            $total = (float) $data['total'];
            $plazos = (int) $data['plazos'];
            $valorFicha = (float) $data['valor_ficha'];

            $ciclo = $this->cicloService->calcularCiclo(
                $creditoAnterior->id_cliente,
                $creditoAnterior->id_grupo
            );

            $nuevoCredito = Credito::create([
                'id_cliente' => $creditoAnterior->id_cliente,
                'id_grupo' => $creditoAnterior->id_grupo,
                'id_asesor' => $creditoAnterior->id_asesor,
                'fecha_otorgacion' => $data['fecha_otorgacion'] ?? now()->toDateString(),
                'fecha_primer_pago' => $data['fecha_primer_pago'],
                'ciclo' => $ciclo,
                'monto_otorgado' => $montoOtorgado,
                'interes' => $interes,
                'total' => $total,
                'saldo_pendiente' => $total,
                'plazos' => $plazos,
                'valor_ficha' => $valorFicha,
                'dias_pago' => $data['dias_pago'] ?? $creditoAnterior->dias_pago,
                'tipo_credito' => $creditoAnterior->tipo_credito,
                'estado' => 'Activo',
                'comision_apertura' => $data['comision_apertura'] ?? 100.00,
                'credito_padre_id' => $creditoAnterior->num_prog,
                'tasa_asignada' => $data['tasa_asignada'] ?? $creditoAnterior->tasa_asignada,
                'porcentaje_interes' => $data['porcentaje_interes'] ?? $creditoAnterior->porcentaje_interes,
                'es_personalizado' => $data['es_personalizado'] ?? true,
            ]);

            Refinanciamiento::create([
                'num_prog_anterior' => $creditoAnterior->num_prog,
                'num_prog_nuevo' => $nuevoCredito->num_prog,
                'saldo_anterior' => $saldoAnterior,
                'deduccion' => $deduccion,
                'monto_neto' => $montoNeto,
                'intereses_arrastrados' => $data['intereses_arrastrados'] ?? 0,
                'notas' => $data['notas'] ?? null,
            ]);

            $creditoAnterior->update(['estado' => 'Finalizado', 'saldo_pendiente' => 0]);
            $this->cicloService->cerrarCiclo($creditoAnterior, 'Refinanciado');
            $this->cicloService->registrarInicio($nuevoCredito);

            $nuevoCredito->load(['cliente', 'grupo']);
            $beneficiario = $nuevoCredito->cliente?->nombre_completo
                ?? $nuevoCredito->grupo?->nombre_grupo
                ?? "Crédito #{$nuevoCredito->num_prog}";

            // Egreso por el efectivo entregado (monto neto = nuevo monto − saldo anterior).
            $this->flujoCajaService->registrarDesdeDesembolso(
                $nuevoCredito,
                $montoNeto,
                "DESEMBOLSO REFINANCIAMIENTO #{$nuevoCredito->num_prog} (origen #{$creditoAnterior->num_prog}) — {$beneficiario}"
            );

            return $nuevoCredito->load(['cliente', 'grupo', 'asesor', 'creditoPadre']);
        });
    }
}
