<?php

namespace App\Services;

use App\Models\Credito;
use App\Models\Pago;
use Carbon\Carbon;

class MoraCalculationService
{
    private function resolveImportedSaldo(Credito $credito): ?float
    {
        $saldo = $credito->saldo_pendiente;
        if ($saldo === null) {
            return null;
        }

        $tabla = $credito->tabla_amortizacion;
        if (!is_array($tabla) || $tabla === []) {
            return null;
        }

        foreach ($tabla as $item) {
            if (!is_array($item)) {
                continue;
            }

            $importRef = $item['import_ref'] ?? null;
            if (is_string($importRef) && str_starts_with($importRef, 'EXCEL-')) {
                return round((float) $saldo, 2);
            }
        }

        return null;
    }

    public function generateSchedule(Credito $credito): array
    {
        if (!$credito->fecha_primer_pago || !$credito->plazos || !$credito->valor_ficha) {
            return [];
        }

        $schedule = [];
        $date = Carbon::parse($credito->fecha_primer_pago);

        for ($i = 0; $i < $credito->plazos; $i++) {
            $schedule[] = [
                'semana' => $i + 1,
                'fecha' => $date->copy()->addWeeks($i)->format('Y-m-d'),
                'pago' => (float) $credito->valor_ficha,
            ];
        }

        return $schedule;
    }

    public function calculate(Credito $credito): array
    {
        $schedule = $this->generateSchedule($credito);
        $pagos = $credito->relationLoaded('pagos')
            ? $credito->pagos
            : $credito->pagos()->orderBy('fecha')->orderBy('hora')->get();

        $abonos = $pagos->where('tipo', 'Abono')->values();
        $multas = $pagos->where('tipo', 'Multa');

        // Solo abonos liquidan el préstamo. Las multas van al asesor y no afectan el saldo.
        $totalAbonado = (float) $abonos->sum('monto');
        $totalMultas = (float) $multas->sum('monto');
        $saldoImportado = $this->resolveImportedSaldo($credito);

        if ($abonos->isEmpty() && $saldoImportado !== null) {
            $saldoPendiente = max(0, $saldoImportado);
            $totalAbonado = max(0, round((float) $credito->total - $saldoPendiente, 2));
        } else {
            $saldoPendiente = max(0, (float) $credito->total - $totalAbonado);
        }

        $ultimoAbono = $abonos
            ->sort(function ($a, $b) {
                $fechaA = $a->fecha ? Carbon::parse($a->fecha)->format('Y-m-d') : '0000-00-00';
                $fechaB = $b->fecha ? Carbon::parse($b->fecha)->format('Y-m-d') : '0000-00-00';
                if ($fechaA !== $fechaB) {
                    return $fechaB <=> $fechaA;
                }

                $horaA = substr((string) ($a->hora ?? '00:00:00'), 0, 8);
                $horaB = substr((string) ($b->hora ?? '00:00:00'), 0, 8);
                if ($horaA !== $horaB) {
                    return $horaB <=> $horaA;
                }

                return ((int) ($b->id ?? 0)) <=> ((int) ($a->id ?? 0));
            })
            ->first();

        $diasMora = 0;
        $cicloInicioMora = $credito->ciclo_inicio_mora;
        $estadoTerminal = in_array($credito->estado, ['Finalizado', 'Cancelado', 'CerradoSinRenovacion'], true);

        // Sin saldo pendiente no hay mora del préstamo, aunque existan multas cobradas.
        if ($saldoPendiente > 0 && !$estadoTerminal && !empty($schedule)) {
            $hoy = Carbon::today();
            $acumuladoEsperado = 0;

            foreach ($schedule as $cuota) {
                $fechaCuota = Carbon::parse($cuota['fecha']);
                $acumuladoEsperado += $cuota['pago'];

                if ($fechaCuota->lte($hoy) && $totalAbonado < $acumuladoEsperado) {
                    $diasMora = max($diasMora, $fechaCuota->diffInDays($hoy));
                    if (!$cicloInicioMora) {
                        $cicloInicioMora = $cuota['semana'];
                    }
                }
            }
        }

        return [
            'ciclo_inicio_mora' => $cicloInicioMora,
            'dias_mora' => (int) $diasMora,
            'plazo_original' => $credito->plazos,
            'monto_original' => (float) $credito->monto_otorgado,
            'total_adeudo' => round($saldoPendiente, 2),
            'multas' => round($totalMultas, 2),
            'abono_recuperacion' => $credito->abono_recuperacion ? (float) $credito->abono_recuperacion : null,
            'deuda_total' => round((float) $credito->total, 2),
            'ultimo_abono' => $ultimoAbono ? [
                'monto' => (float) $ultimoAbono->monto,
                'fecha' => Carbon::parse($ultimoAbono->fecha)->format('Y-m-d'),
                'hora' => $ultimoAbono->hora,
            ] : null,
            'saldo_actual' => round($saldoPendiente, 2),
            'total_abonado' => round($totalAbonado, 2),
            'en_mora' => $diasMora > 0 && !$estadoTerminal,
        ];
    }

    public function syncCreditoState(Credito $credito): void
    {
        $mora = $this->calculate($credito);

        $updates = [
            'dias_mora_cache' => $mora['dias_mora'],
        ];

        if ($mora['ciclo_inicio_mora'] && !$credito->ciclo_inicio_mora) {
            $updates['ciclo_inicio_mora'] = $mora['ciclo_inicio_mora'];
        }

        $estadoTerminal = in_array($credito->estado, ['Finalizado', 'Cancelado', 'CerradoSinRenovacion'], true);

        // No cambiar Activo ↔ EnMora aquí: ese estado viene del Excel (hoja) o de
        // "Enviar a mora" / reactivar manual. Solo liquidar cuando el saldo llega a 0.
        if (!$estadoTerminal) {
            if ($mora['saldo_actual'] <= 0 && in_array($credito->estado, ['Activo', 'EnMora'], true)) {
                $updates['estado'] = 'Finalizado';
                $updates['saldo_pendiente'] = 0;
            } else {
                $updates['saldo_pendiente'] = $mora['saldo_actual'];
            }
        } elseif ($mora['saldo_actual'] >= 0) {
            $updates['saldo_pendiente'] = $mora['saldo_actual'];
        }

        $credito->update($updates);
    }
}
