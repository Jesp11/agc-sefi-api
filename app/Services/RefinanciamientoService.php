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
        private FlujoCajaService $flujoCajaService,
        private PagoService $pagoService,
        private IndicadoresOperativosService $indicadoresOperativosService
    ) {}

    public function refinanciar(Credito $creditoAnterior, array $data): Credito
    {
        if (!in_array($creditoAnterior->estado, ['Activo', 'EnMora'], true)) {
            throw new InvalidArgumentException('Solo se pueden refinanciar créditos activos o en mora.');
        }

        return DB::transaction(function () use ($creditoAnterior, $data) {
            $fechaEfectiva = $data['fecha_efectiva'] ?? $data['fecha_otorgacion'] ?? now()->toDateString();

            $saldoAntesAbono = (float) ($this->moraService->calculate($creditoAnterior->load('pagos'))['saldo_actual'] ?? 0);

            // El abono corresponde al crédito anterior y debe entrar a caja como un cobro normal
            // antes de determinar cuánto saldo se absorberá en la renovación.
            $abonoEfectivo = round((float) ($data['abono_efectivo'] ?? 0), 2);
            if ($abonoEfectivo > $saldoAntesAbono + 0.01) {
                throw new InvalidArgumentException('El abono efectivo no puede exceder el saldo pendiente.');
            }
            if ($abonoEfectivo > 0) {
                $this->pagoService->registrar($creditoAnterior->loadMissing(['cliente', 'grupo']), [
                    'monto' => $abonoEfectivo,
                    'fecha' => $fechaEfectiva,
                    'metodo_pago' => $data['metodo_pago_abono'] ?? 'Efectivo',
                    'notas' => 'Abono efectivo previo a renovación' . (!empty($data['notas']) ? ': ' . $data['notas'] : ''),
                ]);
                $creditoAnterior->refresh();
            }

            $mora = $this->moraService->calculate($creditoAnterior->load('pagos'));
            // Las multas no forman parte del saldo del préstamo (van al asesor).
            $saldoAnterior = (float) $mora['saldo_actual'];

            $montoOtorgado = (float) $data['monto_otorgado'];
            if ($montoOtorgado < $saldoAnterior) {
                throw new InvalidArgumentException('El nuevo monto no puede ser menor al saldo que se absorberá.');
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
                'fecha_otorgacion' => $fechaEfectiva,
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
                'fecha_efectiva' => $fechaEfectiva,
                'notas' => $data['notas'] ?? null,
            ]);

            $creditoAnterior->update([
                'estado' => 'Finalizado',
                'saldo_pendiente' => 0,
                'fecha_programada_renovacion' => null,
                'renovacion_autorizada' => 'Pendiente',
                'renovacion_tasa' => null,
            ]);
            $this->cicloService->cerrarCiclo($creditoAnterior, 'Refinanciado');
            $this->cicloService->registrarInicio($nuevoCredito);

            $nuevoCredito->load(['cliente', 'grupo']);
            $beneficiario = $nuevoCredito->cliente?->nombre_completo
                ?? $nuevoCredito->grupo?->nombre_grupo
                ?? "Crédito #{$nuevoCredito->num_prog}";

            // Egreso por el efectivo entregado; con neto cero no se genera movimiento de caja.
            $this->flujoCajaService->registrarDesdeDesembolso(
                $nuevoCredito,
                $montoNeto,
                "RENOVACIÓN A {$plazos} SEMANAS — {$beneficiario}",
                'Renovacion'
            );

            $this->indicadoresOperativosService->registrarAumentoCartera(
                $nuevoCredito->fecha_otorgacion ?? now()->toDateString(),
                $montoNeto,
                $nuevoCredito,
                $creditoAnterior,
                'creditos.refinanciamiento',
                "Aumento de cartera por refinanciamiento de {$beneficiario}",
                [
                    'num_prog_anterior' => $creditoAnterior->num_prog,
                    'saldo_anterior' => round($saldoAnterior, 2),
                    'monto_otorgado_nuevo' => round($montoOtorgado, 2),
                    'monto_neto' => round($montoNeto, 2),
                ]
            );

            return $nuevoCredito->load(['cliente', 'grupo', 'asesor', 'creditoPadre']);
        });
    }
}
