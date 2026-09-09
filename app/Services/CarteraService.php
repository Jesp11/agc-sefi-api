<?php

namespace App\Services;

use App\Models\Credito;
use App\Models\Pago;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CarteraService
{
    private const DIAS_SEMANA = [
        0 => 'DOMINGO',
        1 => 'LUNES',
        2 => 'MARTES',
        3 => 'MIERCOLES',
        4 => 'JUEVES',
        5 => 'VIERNES',
        6 => 'SABADO',
    ];

    public function __construct(
        private MoraCalculationService $moraService,
        private CicloService $cicloService,
        private ClienteService $clienteService,
        private IndicadoresOperativosService $indicadoresOperativosService
    ) {}

    /**
     * Cobros a realizar en un día: cuota del día de pago + pendientes de días anteriores.
     * Si el usuario es asesor, solo ve su cartera.
     */
    public function cobrosDelDia(?string $fecha = null, ?int $idAsesor = null): array
    {
        $fechaRef = Carbon::parse($fecha ?? now()->toDateString())->startOfDay();
        $diaSemana = self::DIAS_SEMANA[$fechaRef->dayOfWeek];

        $query = Credito::with(['cliente', 'grupo', 'asesor', 'pagos'])
            ->where(function ($q) use ($fechaRef) {
                $q->where('estado', 'Activo')
                  ->orWhere(function ($sub) use ($fechaRef) {
                      $sub->where('estado', 'Finalizado')
                          ->where(function ($historical) use ($fechaRef) {
                              // Un crédito renovado conserva su ruta únicamente antes de la
                              // fecha efectiva. En esa fecha y posteriores lo reemplaza el nuevo.
                              $historical->whereHas('refinanciamientosComoAnterior', function ($rq) use ($fechaRef) {
                                  $rq->whereDate('fecha_efectiva', '>', $fechaRef->toDateString());
                              })->orWhere(function ($legacy) use ($fechaRef) {
                                  $legacy->whereDoesntHave('refinanciamientosComoAnterior')
                                      ->whereHas('pagos', function ($pq) use ($fechaRef) {
                                          $pq->whereDate('fecha', '>=', $fechaRef->toDateString());
                                      });
                              });
                          });
                  });
            });

        if ($idAsesor) {
            $query->where('id_asesor', $idAsesor);
        }

        $cobros = [];
        foreach ($query->get() as $credito) {
            $item = $this->buildCobroItem($credito, $fechaRef, $diaSemana);
            if ($item) {
                $cobros[] = $item;
            }
        }

        // La mora se presenta en una sección propia: no es parte de la ruta
        // ordinaria ni de los atrasados, pero el gestor puede registrar su
        // abono desde el reporte diario.
        $creditosMora = Credito::with(['cliente', 'grupo', 'asesor', 'pagos'])
            ->where('estado', 'EnMora')
            ->when($idAsesor, fn ($q) => $q->where('id_asesor', $idAsesor))
            ->get()
            ->map(function (Credito $credito) use ($fechaRef) {
                $mora = $this->moraService->calculate($credito);
                $pagadoHoy = $credito->pagos
                    ->where('tipo', 'Abono')
                    ->contains(fn ($pago) => $pago->fecha
                        && Carbon::parse($pago->fecha)->isSameDay($fechaRef));

                return [
                    'num_prog' => $credito->num_prog,
                    'tipo_credito' => $credito->tipo_credito,
                    'dias_pago' => $credito->dias_pago,
                    'saldo_actual' => (float) ($mora['saldo_actual'] ?? $credito->saldo_pendiente ?? 0),
                    'dias_mora' => (int) ($mora['dias_mora'] ?? $credito->dias_mora_cache ?? 0),
                    'pagado_hoy' => $pagadoHoy,
                    'cliente' => $credito->cliente?->toArray() ?? [],
                    'grupo' => $credito->grupo?->toArray(),
                    'asesor' => $credito->asesor?->toArray(),
                ];
            })
            ->values();

        usort($cobros, function (array $a, array $b) {
            $orden = ['atrasado' => 0, 'del_dia' => 1];
            $cmp = ($orden[$a['categoria']] ?? 9) <=> ($orden[$b['categoria']] ?? 9);
            if ($cmp !== 0) {
                return $cmp;
            }
            return ($b['dias_atraso'] ?? 0) <=> ($a['dias_atraso'] ?? 0);
        });

        $pagosDelDia = Pago::with(['credito.cliente', 'credito.grupo', 'credito.asesor'])
            ->whereDate('fecha', $fechaRef->toDateString())
            ->whereHas('credito', function ($q) use ($idAsesor) {
                $q->whereIn('estado', ['Activo', 'Finalizado', 'EnMora']);
                if ($idAsesor) {
                    $q->where('id_asesor', $idAsesor);
                }
            })
            ->get();

        $montoCobrado = (float) $pagosDelDia->where('tipo', 'Abono')->sum('monto');
        $montoMultas = (float) $pagosDelDia->where('tipo', 'Multa')->sum('monto');

        return [
            'fecha' => $fechaRef->toDateString(),
            'dia_semana' => $diaSemana,
            'total_cobros' => count($cobros),
            'total_del_dia' => count(array_filter($cobros, fn ($c) => $c['categoria'] === 'del_dia')),
            'total_atrasados' => count(array_filter($cobros, fn ($c) => $c['categoria'] === 'atrasado')),
            // El total de ruta no incluye atrasados: éstos sólo se reflejan
            // en el monto cobrado cuando el gestor registra su abono.
            'monto_a_cobrar' => round(array_sum(array_column(
                array_filter($cobros, fn ($c) => $c['categoria'] === 'del_dia'),
                'monto_a_cobrar'
            )), 2),
            'monto_cobrado' => round($montoCobrado, 2),
            'num_abonos' => $pagosDelDia->where('tipo', 'Abono')->count(),
            'monto_multas' => round($montoMultas, 2),
            // La vista diaria del gestor usa estos datos para mostrar sus
            // abonos capturados y permitir reimprimir cada comprobante.
            'pagos' => $pagosDelDia->where('tipo', 'Abono')->values(),
            'cobros' => $cobros,
            'creditos_mora' => $creditosMora,
        ];
    }

    private function buildCobroItem(Credito $credito, Carbon $fechaRef, string $diaSemana): ?array
    {
        if (!in_array($credito->estado, ['Activo', 'Finalizado'], true)) {
            return null;
        }

        $schedule = $this->moraService->generateSchedule($credito);
        if ($schedule === []) {
            return null;
        }

        $abonado = (float) $credito->pagos
            ->where('tipo', 'Abono')
            ->filter(fn ($p) => $p->fecha && Carbon::parse($p->fecha)->startOfDay()->lt($fechaRef))
            ->sum('monto');
        $restanteAbonado = $abonado;
        $pendientes = [];

        foreach ($schedule as $cuota) {
            $monto = (float) $cuota['pago'];
            $fechaCuota = Carbon::parse($cuota['fecha'])->startOfDay();

            if ($restanteAbonado >= $monto - 0.01) {
                $restanteAbonado -= $monto;
                continue;
            }

            $falta = round($monto - $restanteAbonado, 2);
            $restanteAbonado = 0;

            if ($fechaCuota->lte($fechaRef)) {
                $pendientes[] = [
                    'semana' => $cuota['semana'],
                    'fecha' => $fechaCuota->toDateString(),
                    'monto' => $falta,
                    'atrasada' => $fechaCuota->lt($fechaRef),
                ];
            }
        }

        if ($pendientes === []) {
            return null;
        }

        // Por petición: Solo agendar la cuota más antigua pendiente (una sola).
        $oldest = $pendientes[0];
        $pendientesParaCobro = [$oldest];

        $tieneAtrasadas = $oldest['atrasada'];
        $diaPago = $this->normalizarDiaPago($credito->dias_pago);
        $esDiaPago = $diaPago === $diaSemana;
        // Del día: clientes cuyo día asignado es hoy.
        // Atrasados: clientes de otros días que deben cuotas pasadas.
        if (!$tieneAtrasadas && !$esDiaPago) {
            return null;
        }

        $categoria = $esDiaPago ? 'del_dia' : 'atrasado';
        $clasificacionAbonosHoy = $this->clasificarAbonosDelDia($credito, $fechaRef);
        $montoAbonadoAtrasado = round((float) collect($clasificacionAbonosHoy)->sum('atrasado'), 2);
        $montoAbonadoDelDia = round((float) collect($clasificacionAbonosHoy)->sum('del_dia'), 2);
        // Si el crédito está programado hoy y además arrastra una cuota, los
        // dos importes corresponden a cobranza exigible; los anticipados se
        // excluyen para no inflar lo abonado de ruta o atraso.
        $abonosHoy = $categoria === 'atrasado'
            ? $montoAbonadoAtrasado
            : round($montoAbonadoAtrasado + $montoAbonadoDelDia, 2);
        $pagadoHoy = $abonosHoy > 0.009;
        // La ruta del día siempre cobra la ficha completa. Los abonos previos
        // pueden servir para el saldo del crédito, pero no deben descontarse
        // automáticamente de la cuota que se muestra al gestor.
        $montoCobro = $categoria === 'del_dia'
            ? (float) $credito->valor_ficha
            : (float) $oldest['monto'];
        if ($categoria === 'del_dia') {
            $pendientesParaCobro[0]['monto'] = $montoCobro;
        }
        $diasAtraso = 0;
        if ($tieneAtrasadas) {
            $diasAtraso = Carbon::parse($oldest['fecha'])->diffInDays($fechaRef);
        }

        return [
            'num_prog' => $credito->num_prog,
            'tipo_credito' => $credito->tipo_credito,
            'estado' => $credito->estado,
            'dias_pago' => $credito->dias_pago,
            'ciclo' => $credito->ciclo,
            'valor_ficha' => (float) $credito->valor_ficha,
            'saldo_pendiente' => (float) $credito->saldo_pendiente,
            'monto_a_cobrar' => round($montoCobro, 2),
            'cuotas_pendientes' => count($pendientes), // Total real pendiente
            'cuotas_atrasadas' => collect($pendientes)->where('atrasada', true)->count(),
            'dias_atraso' => $diasAtraso,
            'categoria' => $categoria,
            // La ruta conserva el crédito después de un abono para que el
            // gestor tenga confirmación visual de lo que ya cobró.
            'pagado_hoy' => $pagadoHoy,
            // Conserva la suma de todos los abonos capturados en la fecha;
            // un cliente atrasado puede cubrir más de una cuota en una visita.
            'monto_abonado_hoy' => round($abonosHoy, 2),
            'monto_abonado_atrasado_hoy' => $montoAbonadoAtrasado,
            'monto_abonado_del_dia_hoy' => $montoAbonadoDelDia,
            'cliente' => $credito->cliente?->toArray() ?? [],
            'grupo' => $credito->grupo?->toArray(),
            'asesor' => $credito->asesor?->toArray(),
            'pendientes' => $pendientesParaCobro,
        ];
    }

    /**
     * Distribuye los abonos de una fecha entre cuotas vencidas, del día y
     * futuras. Así un segundo o tercer pago no se etiqueta como atrasado una
     * vez que las cuotas vencidas ya quedaron cubiertas.
     *
     * @return array<int, array{atrasado: float, del_dia: float, adelantado: float}>
     */
    public function clasificarAbonosDelDia(Credito $credito, Carbon|string $fecha, $pagosCredito = null): array
    {
        $fechaRef = $fecha instanceof Carbon ? $fecha->copy()->startOfDay() : Carbon::parse($fecha)->startOfDay();
        $pagos = collect($pagosCredito ?? $credito->pagos)
            ->where('tipo', 'Abono');
        $cuotas = collect($this->moraService->generateSchedule($credito))
            ->map(fn (array $cuota) => [
                'fecha' => Carbon::parse($cuota['fecha'])->startOfDay(),
                'saldo' => round((float) $cuota['pago'], 2),
            ])->all();
        $abonoPrevio = (float) $pagos
            ->filter(fn (Pago $pago) => $pago->fecha && Carbon::parse($pago->fecha)->startOfDay()->lt($fechaRef))
            ->sum('monto');

        foreach ($cuotas as &$cuota) {
            if ($abonoPrevio <= 0.009) {
                break;
            }
            $aplicado = min($abonoPrevio, $cuota['saldo']);
            $cuota['saldo'] = round($cuota['saldo'] - $aplicado, 2);
            $abonoPrevio = round($abonoPrevio - $aplicado, 2);
        }
        unset($cuota);

        $resultado = [];
        foreach ($pagos
            ->filter(fn (Pago $pago) => $pago->fecha && Carbon::parse($pago->fecha)->isSameDay($fechaRef))
            ->sortBy([['hora', 'asc'], ['id', 'asc']]) as $pago) {
            $detalle = ['atrasado' => 0.0, 'del_dia' => 0.0, 'adelantado' => 0.0];
            $restante = (float) $pago->monto;

            foreach ($cuotas as &$cuota) {
                if ($restante <= 0.009 || $cuota['saldo'] <= 0.009) {
                    continue;
                }
                $aplicado = min($restante, $cuota['saldo']);
                $tipo = $cuota['fecha']->lt($fechaRef)
                    ? 'atrasado'
                    : ($cuota['fecha']->isSameDay($fechaRef) ? 'del_dia' : 'adelantado');
                $detalle[$tipo] = round($detalle[$tipo] + $aplicado, 2);
                $cuota['saldo'] = round($cuota['saldo'] - $aplicado, 2);
                $restante = round($restante - $aplicado, 2);
            }
            unset($cuota);

            // Un importe que excede el calendario también permanece a favor
            // del cliente y se reporta como pago adelantado.
            if ($restante > 0.009) {
                $detalle['adelantado'] = round($detalle['adelantado'] + $restante, 2);
            }
            $resultado[(int) $pago->id] = $detalle;
        }

        return $resultado;
    }

    private function normalizarDiaPago(?string $diasPago): string
    {
        $dia = strtoupper(trim((string) $diasPago));
        $dia = strtr($dia, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
        $mapa = [
            'MIÉRCOLES' => 'MIERCOLES',
            'MIERCOLES' => 'MIERCOLES',
            'SÁBADO' => 'SABADO',
            'SABADO' => 'SABADO',
        ];
        return $mapa[$dia] ?? $dia;
    }

    public function enviarAMora(Credito $credito): Credito
    {
        if (!in_array($credito->estado, ['Activo', 'EnMora'], true)) {
            throw new InvalidArgumentException('Solo se pueden enviar a mora créditos activos.');
        }

        if ($credito->estado === 'EnMora') {
            return $credito->fresh(['cliente', 'grupo', 'asesor']);
        }

        $mora = $this->moraService->calculate($credito);
        $diasMora = max((int) $mora['dias_mora'], 1);
        $cicloInicio = $mora['ciclo_inicio_mora'] ?? $credito->ciclo_inicio_mora ?? 1;
        $saldoActual = round((float) ($mora['saldo_actual'] ?? $credito->saldo_pendiente ?? 0), 2);

        $credito->update([
            'estado' => 'EnMora',
            'dias_mora_cache' => $diasMora,
            'ciclo_inicio_mora' => $cicloInicio,
        ]);

        $beneficiario = $credito->cliente?->nombre_completo
            ?? $credito->grupo?->nombre_grupo
            ?? "Crédito #{$credito->num_prog}";

        $this->indicadoresOperativosService->registrarPaseMora(
            now()->toDateString(),
            $saldoActual,
            $credito,
            'cartera.enviar_mora',
            "Pase a mora de {$beneficiario}",
            [
                'dias_mora' => $diasMora,
                'ciclo_inicio_mora' => $cicloInicio,
                'saldo_pendiente' => round((float) ($credito->saldo_pendiente ?? 0), 2),
                'saldo_actual_calculado' => $saldoActual,
            ]
        );

        return $credito->fresh(['cliente', 'grupo', 'asesor']);
    }

    public function cerrarSinRenovacion(Credito $credito): Credito
    {
        if (in_array($credito->estado, ['Finalizado', 'Cancelado', 'CerradoSinRenovacion'], true)) {
            throw new InvalidArgumentException('Este crédito ya está cerrado.');
        }

        return DB::transaction(function () use ($credito) {
            $credito->update(['estado' => 'CerradoSinRenovacion']);
            $this->cicloService->cerrarCiclo($credito, 'CerradoSR');

            if ($credito->id_cliente) {
                $cliente = $credito->cliente;
                if ($cliente) {
                    $this->clienteService->marcarCerradoSinRenovacion($cliente);
                }
            } elseif ($credito->id_grupo) {
                $credito->load('grupo.clientes');
                foreach ($credito->grupo?->clientes ?? [] as $integrante) {
                    $this->clienteService->marcarCerradoSinRenovacion($integrante);
                }
            }

            return $credito->fresh(['cliente', 'grupo', 'asesor']);
        });
    }

    public function reactivar(Credito $credito): Credito
    {
        if (!in_array($credito->estado, ['CerradoSinRenovacion', 'Finalizado'], true)) {
            throw new InvalidArgumentException('Solo se pueden reactivar créditos cerrados.');
        }

        return DB::transaction(function () use ($credito) {
            $credito->update(['estado' => 'Activo']);

            if ($credito->id_cliente) {
                $this->clienteService->reactivar($credito->cliente);
            } elseif ($credito->id_grupo) {
                $credito->load('grupo.clientes');
                foreach ($credito->grupo?->clientes ?? [] as $integrante) {
                    $this->clienteService->reactivar($integrante);
                }
            }

            return $credito->fresh(['cliente', 'grupo', 'asesor']);
        });
    }
}
