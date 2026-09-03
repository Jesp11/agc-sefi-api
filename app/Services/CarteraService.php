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
                          ->whereHas('pagos', function ($pq) use ($fechaRef) {
                              $pq->whereDate('fecha', '>=', $fechaRef->toDateString());
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

        usort($cobros, function (array $a, array $b) {
            $orden = ['atrasado' => 0, 'del_dia' => 1];
            $cmp = ($orden[$a['categoria']] ?? 9) <=> ($orden[$b['categoria']] ?? 9);
            if ($cmp !== 0) {
                return $cmp;
            }
            return ($b['dias_atraso'] ?? 0) <=> ($a['dias_atraso'] ?? 0);
        });

        $pagosDelDia = Pago::query()
            ->whereDate('fecha', $fechaRef->toDateString())
            ->whereHas('credito', function ($q) use ($idAsesor) {
                $q->whereIn('estado', ['Activo', 'Finalizado']);
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
            'monto_a_cobrar' => round(array_sum(array_column($cobros, 'monto_a_cobrar')), 2),
            'monto_cobrado' => round($montoCobrado, 2),
            'num_abonos' => $pagosDelDia->where('tipo', 'Abono')->count(),
            'monto_multas' => round($montoMultas, 2),
            'cobros' => $cobros,
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
            'monto_a_cobrar' => round($oldest['monto'], 2),
            'cuotas_pendientes' => count($pendientes), // Total real pendiente
            'cuotas_atrasadas' => collect($pendientes)->where('atrasada', true)->count(),
            'dias_atraso' => $diasAtraso,
            'categoria' => $categoria,
            'cliente' => $credito->cliente?->toArray() ?? [],
            'grupo' => $credito->grupo?->toArray(),
            'asesor' => $credito->asesor?->toArray(),
            'pendientes' => $pendientesParaCobro,
        ];
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
