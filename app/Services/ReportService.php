<?php

namespace App\Services;

use App\Models\AhorroEmpleado;
use App\Models\Aportacion;
use App\Models\Asesor;
use App\Models\Credito;
use App\Models\Inversionista;
use App\Models\Pago;
use App\Models\RecepcionAsesor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReportService
{
    public function __construct(
        private MoraCalculationService $moraService
    ) {}

    public function reporteDiario(?string $fecha = null, ?int $idAsesor = null): array
    {
        $fecha = $fecha ?? now()->toDateString();

        $pagosQuery = Pago::with(['credito.cliente', 'credito.grupo', 'credito.asesor'])
            ->whereDate('fecha', $fecha);

        $creditosQuery = Credito::with(['cliente', 'grupo', 'asesor'])
            ->whereDate('fecha_otorgacion', $fecha);

        if ($idAsesor) {
            $pagosQuery->whereHas('credito', fn ($q) => $q->where('id_asesor', $idAsesor));
            $creditosQuery->where('id_asesor', $idAsesor);
        } else {
            // Vista admin: solo abonos (las multas son íntegras del asesor).
            $pagosQuery->where('tipo', 'Abono');
        }

        $pagos = $pagosQuery->get();
        $creditos = $creditosQuery->get();

        $payload = [
            'fecha' => $fecha,
            'total_abonos' => round((float) $pagos->where('tipo', 'Abono')->sum('monto'), 2),
            'creditos_otorgados' => $creditos->count(),
            'monto_colocado' => round((float) $creditos->sum('monto_otorgado'), 2),
            'pagos' => $pagos->values(),
            'creditos' => $creditos,
        ];

        if ($idAsesor) {
            $payload['total_multas'] = round((float) $pagos->where('tipo', 'Multa')->sum('monto'), 2);
        } else {
            // Resumen por asesor: lo que cada uno cobró (abonos) y debe entregar a la financiera.
            $payload['por_asesor'] = $pagos
                ->groupBy(fn ($pago) => $pago->credito?->id_asesor ?? 0)
                ->map(function ($grupoPagos, $asesorId) use ($creditos) {
                    $asesor = $grupoPagos->first()?->credito?->asesor;
                    $creditosAsesor = $creditos->where('id_asesor', (int) $asesorId);

                    return [
                        'id_asesor' => (int) $asesorId ?: null,
                        'nombre_asesor' => $asesor?->nombre_asesor ?? 'Sin asesor',
                        'codigo_asesor' => $asesor?->id_asesor,
                        'num_abonos' => $grupoPagos->count(),
                        'total_cobrado' => round((float) $grupoPagos->sum('monto'), 2),
                        'a_recibir' => round((float) $grupoPagos->sum('monto'), 2),
                        'creditos_otorgados' => $creditosAsesor->count(),
                        'monto_colocado' => round((float) $creditosAsesor->sum('monto_otorgado'), 2),
                    ];
                })
                ->sortByDesc('a_recibir')
                ->values()
                ->all();

            // Incluir asesores que solo colocaron créditos ese día (sin abonos).
            $asesoresConAbonos = collect($payload['por_asesor'])->pluck('id_asesor')->filter()->all();
            foreach ($creditos->groupBy('id_asesor') as $asesorId => $grupoCreditos) {
                if (in_array((int) $asesorId, $asesoresConAbonos, true)) {
                    continue;
                }
                $asesor = $grupoCreditos->first()?->asesor;
                $payload['por_asesor'][] = [
                    'id_asesor' => (int) $asesorId ?: null,
                    'nombre_asesor' => $asesor?->nombre_asesor ?? 'Sin asesor',
                    'codigo_asesor' => $asesor?->id_asesor,
                    'num_abonos' => 0,
                    'total_cobrado' => 0.0,
                    'a_recibir' => 0.0,
                    'creditos_otorgados' => $grupoCreditos->count(),
                    'monto_colocado' => round((float) $grupoCreditos->sum('monto_otorgado'), 2),
                ];
            }

            $recepciones = RecepcionAsesor::whereDate('fecha', $fecha)
                ->get()
                ->keyBy('id_asesor');

            $payload['por_asesor'] = collect($payload['por_asesor'])->map(function (array $row) use ($recepciones) {
                $recepcion = $row['id_asesor'] ? $recepciones->get($row['id_asesor']) : null;
                $recibido = $recepcion ? (float) $recepcion->monto_recibido : null;
                $aRecibir = (float) $row['a_recibir'];

                $row['monto_recibido'] = $recibido;
                $row['recibido'] = $recibido !== null;
                $row['diferencia'] = $recibido !== null ? round($recibido - $aRecibir, 2) : null;
                $row['pendiente_entrega'] = $recibido !== null
                    ? round(max(0, $aRecibir - $recibido), 2)
                    : $aRecibir;
                $row['recepcion_id'] = $recepcion?->id;
                $row['recepcion_notas'] = $recepcion?->notas;
                $row['recibido_at'] = $recepcion?->updated_at?->toDateTimeString();

                return $row;
            })->values()->all();

            $payload['total_a_recibir'] = $payload['total_abonos'];
            $payload['total_recibido'] = round(
                (float) collect($payload['por_asesor'])->sum(fn ($r) => $r['monto_recibido'] ?? 0),
                2
            );
            $payload['total_pendiente'] = round(
                (float) collect($payload['por_asesor'])->sum(fn ($r) => $r['pendiente_entrega'] ?? 0),
                2
            );
        }

        return $payload;
    }

    /**
     * Admin registra el efectivo recibido de un asesor en un día.
     */
    public function registrarRecepcionAsesor(string $fecha, int $idAsesor, float $montoRecibido, ?string $notas = null): RecepcionAsesor
    {
        if ($montoRecibido < 0) {
            throw new InvalidArgumentException('El monto recibido no puede ser negativo.');
        }

        Asesor::findOrFail($idAsesor);

        $esperado = (float) Pago::whereDate('fecha', $fecha)
            ->where('tipo', 'Abono')
            ->whereHas('credito', fn ($q) => $q->where('id_asesor', $idAsesor))
            ->sum('monto');

        return RecepcionAsesor::updateOrCreate(
            [
                'fecha' => $fecha,
                'id_asesor' => $idAsesor,
            ],
            [
                'monto_esperado' => round($esperado, 2),
                'monto_recibido' => round($montoRecibido, 2),
                'notas' => $notas,
                'registrado_por' => Auth::id(),
            ]
        )->load(['asesor', 'registradoPor:id,name']);
    }

    public function cartera(string $tipo = 'general', ?int $idAsesor = null): array
    {
        $query = Credito::with(['cliente', 'grupo', 'asesor', 'pagos']);

        if ($tipo === 'individual') {
            $query->where('tipo_credito', 'Individual')->where('estado', 'Activo');
        } elseif ($tipo === 'grupal') {
            $query->where('tipo_credito', 'Grupal')->where('estado', 'Activo');
        } elseif ($tipo === 'mora') {
            $query->where('estado', 'EnMora');
        } elseif ($tipo === 'cerrados') {
            $query->whereIn('estado', ['CerradoSinRenovacion', 'Finalizado']);
        } elseif ($tipo === 'general') {
            $query->where('estado', 'Activo');
        }

        if ($idAsesor) {
            $query->where('id_asesor', $idAsesor);
        }

        $creditos = $query->get()->map(function ($credito) {
            return array_merge($credito->toArray(), [
                'mora' => $this->moraService->calculate($credito),
            ]);
        });

        return ['creditos' => $creditos, 'total' => $creditos->count()];
    }

    public function moraPorAsesor(?int $idAsesor = null): array
    {
        $query = Credito::with(['cliente', 'grupo', 'asesor', 'pagos'])
            ->where('estado', 'EnMora');

        if ($idAsesor) {
            $query->where('id_asesor', $idAsesor);
        }

        return $query->get()->map(function ($credito) {
            return array_merge($credito->toArray(), [
                'mora' => $this->moraService->calculate($credito),
            ]);
        })->toArray();
    }

    public function clientesPorCerrar(?int $idAsesor = null): array
    {
        $creditos = Credito::with(['cliente', 'grupo', 'asesor', 'pagos'])
            ->whereIn('estado', ['Activo', 'EnMora'])
            ->when($idAsesor, fn ($q) => $q->where('id_asesor', $idAsesor))
            ->get();

        $result = [];
        $hoy = Carbon::today();

        foreach ($creditos as $credito) {
            $valorFicha = (float) ($credito->valor_ficha ?? 0);
            $plazos = (int) ($credito->plazos ?? 0);
            if ($valorFicha <= 0 || $plazos <= 0 || !$credito->fecha_primer_pago) {
                continue;
            }

            $mora = $this->moraService->calculate($credito);
            $saldo = (float) $mora['saldo_actual'];

            // Solo créditos a un abono de liquidarse (último pago).
            if ($saldo <= 0 || $saldo > $valorFicha + 0.01) {
                continue;
            }

            $schedule = $this->moraService->generateSchedule($credito);
            if (empty($schedule)) {
                continue;
            }

            $totalAbonado = (float) $mora['total_abonado'];
            $fechaUltimoAbono = null;
            $acumulado = 0.0;

            foreach ($schedule as $cuota) {
                $acumulado += (float) $cuota['pago'];
                if ($totalAbonado < $acumulado - 0.01) {
                    $fechaUltimoAbono = $cuota['fecha'];
                    break;
                }
            }

            if (!$fechaUltimoAbono) {
                $fechaUltimoAbono = $schedule[array_key_last($schedule)]['fecha'];
            }

            $fecha = Carbon::parse($fechaUltimoAbono);
            $data = $credito->toArray();
            unset($data['pagos']);

            $result[] = array_merge($data, [
                'saldo_actual' => round($saldo, 2),
                'monto_ultimo_abono' => round($saldo, 2),
                'fecha_ultimo_abono' => $fecha->format('Y-m-d'),
                'dias_restantes' => (int) $hoy->diffInDays($fecha, false),
                'pago_semanal' => $valorFicha,
                'plazos' => $plazos,
            ]);
        }

        usort($result, fn ($a, $b) => strcmp($a['fecha_ultimo_abono'], $b['fecha_ultimo_abono']));

        return $result;
    }

    public function reporteInversionistas(): array
    {
        $inversionistas = Inversionista::where('activo', true)
            ->with('aportaciones')
            ->get()
            ->map(function ($inv) {
                $aportado = $inv->aportaciones->where('tipo', 'Aportacion')->sum('monto')
                    - $inv->aportaciones->where('tipo', 'Retiro')->sum('monto');
                return array_merge($inv->toArray(), ['total_aportado' => round((float) $aportado, 2)]);
            });

        return [
            'total_activos' => $inversionistas->count(),
            'total_aportado' => round((float) $inversionistas->sum('total_aportado'), 2),
            'inversionistas' => $inversionistas,
        ];
    }

    public function reporteAhorros(): array
    {
        return [
            'total_saldo' => round((float) AhorroEmpleado::sum('saldo'), 2),
            'ahorros' => AhorroEmpleado::with(['empleado', 'movimientos'])->get(),
        ];
    }

    public function comparativas(string $periodo1Inicio, string $periodo1Fin, string $periodo2Inicio, string $periodo2Fin): array
    {
        $metricas = fn ($inicio, $fin) => [
            'abonos' => Pago::where('tipo', 'Abono')->whereBetween('fecha', [$inicio, $fin])->sum('monto'),
            'colocacion' => Credito::whereBetween('fecha_otorgacion', [$inicio, $fin])->sum('monto_otorgado'),
            'creditos_nuevos' => Credito::whereBetween('fecha_otorgacion', [$inicio, $fin])->count(),
            'mora' => Credito::whereBetween('updated_at', [$inicio, $fin])->where('dias_mora_cache', '>', 0)->count(),
        ];

        return [
            'periodo1' => array_merge(['inicio' => $periodo1Inicio, 'fin' => $periodo1Fin], $metricas($periodo1Inicio, $periodo1Fin)),
            'periodo2' => array_merge(['inicio' => $periodo2Inicio, 'fin' => $periodo2Fin], $metricas($periodo2Inicio, $periodo2Fin)),
        ];
    }

    public function reporteSemanal(?string $semanaInicio = null): array
    {
        $inicio = $semanaInicio
            ? Carbon::parse($semanaInicio)->startOfWeek(Carbon::MONDAY)
            : now()->startOfWeek(Carbon::MONDAY);
        $fin = $inicio->copy()->addDays(5); // Lunes a Sábado

        $dias = [];
        for ($d = $inicio->copy(); $d->lte($fin); $d->addDay()) {
            $dias[] = $this->reporteDiario($d->format('Y-m-d'));
        }

        return [
            'semana_inicio' => $inicio->format('Y-m-d'),
            'semana_fin' => $fin->format('Y-m-d'),
            'dias' => $dias,
            'totales' => [
                'abonos' => round(collect($dias)->sum('total_abonos'), 2),
                'colocacion' => round(collect($dias)->sum('monto_colocado'), 2),
                'creditos' => collect($dias)->sum('creditos_otorgados'),
            ],
        ];
    }
}
