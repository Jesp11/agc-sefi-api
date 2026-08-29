<?php

namespace App\Services;

use App\Models\AhorroEmpleado;
use App\Models\AhorroPersonal;
use App\Models\AhorroSocio;
use App\Models\Aportacion;
use App\Models\Asesor;
use App\Models\Credito;
use App\Models\GastoOperativo;
use App\Models\Inversionista;
use App\Models\MovimientoCaja;
use App\Models\Pago;
use App\Models\RecepcionAsesor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class ReportService
{
    public function __construct(
        private MoraCalculationService $moraService,
        private FlujoCajaService $flujoCajaService,
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
        } elseif ($tipo === 'mora_activa') {
            $query->where('estado', 'EnMora');
        } elseif ($tipo === 'mora_muerta') {
            $query->whereIn('estado', ['CerradoSinRenovacion', 'Finalizado'])
                ->where(function ($q) {
                    $q->whereNotNull('ciclo_inicio_mora')
                        ->orWhere('dias_mora_cache', '>', 0);
                });
        } elseif ($tipo === 'cerrados') {
            $query->whereIn('estado', ['CerradoSinRenovacion', 'Finalizado']);
        } elseif ($tipo === 'general') {
            $query->where('estado', 'Activo');
        }

        if ($idAsesor) {
            $query->where('id_asesor', $idAsesor);
        }

        $creditos = $query->get()->map(function ($credito) {
            $mora = $this->moraService->calculate($credito);
            $saldoTotal = round((float) $mora['saldo_actual'], 2);
            $saldoInversion = round($saldoTotal - (float) ($credito->interes ?? 0), 2);
            $semanasRestantes = 0;
            if ((float) ($credito->valor_ficha ?? 0) > 0 && $saldoTotal > 0) {
                $semanasRestantes = (int) ceil($saldoTotal / (float) $credito->valor_ficha);
            }

            $payload = [
                'mora' => $mora,
                'saldo_total' => $saldoTotal,
                'saldo_inversion' => $saldoInversion,
                'semanas_restantes' => $semanasRestantes,
                'pagos_programados' => $this->buildPagosProgramados($credito),
            ];

            if ($credito->tipo_credito === 'Grupal') {
                $payload = array_merge($payload, $this->buildGroupMetrics($credito, $saldoTotal));
            }

            return array_merge($credito->toArray(), $payload);
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

    public function carteraMoraClasificada(string $tipo, ?int $idAsesor = null): array
    {
        $query = Credito::with(['cliente', 'grupo', 'asesor', 'pagos']);

        if ($tipo === 'mora-activa') {
            $query->where('estado', 'EnMora');
        } else {
            $query->whereIn('estado', ['CerradoSinRenovacion', 'Finalizado'])
                ->where(function ($q) {
                    $q->whereNotNull('ciclo_inicio_mora')
                        ->orWhere('dias_mora_cache', '>', 0);
                });
        }

        if ($idAsesor) {
            $query->where('id_asesor', $idAsesor);
        }

        $creditos = $query->get()->map(function ($credito) use ($tipo) {
            return array_merge($credito->toArray(), [
                'mora' => $this->moraService->calculate($credito),
                'clasificacion_mora' => $tipo === 'mora-activa' ? 'mora_activa' : 'mora_muerta',
            ]);
        });

        return ['creditos' => $creditos->values(), 'total' => $creditos->count()];
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

            if ($saldo <= 0) {
                continue;
            }

            $schedule = $this->moraService->generateSchedule($credito);
            if (empty($schedule)) {
                continue;
            }

            $totalAbonado = (float) $mora['total_abonado'];
            $fechaUltimoAbono = null;
            $acumulado = 0.0;
            $pagosRestantes = 0;

            foreach ($schedule as $cuota) {
                $acumulado += (float) $cuota['pago'];
                if ($totalAbonado < $acumulado - 0.01) {
                    $pagosRestantes++;
                    if (! $fechaUltimoAbono) {
                        $fechaUltimoAbono = $cuota['fecha'];
                    }
                }
            }

            if (!$fechaUltimoAbono || $pagosRestantes < 1 || $pagosRestantes > 6) {
                continue;
            }

            $fecha = Carbon::parse($fechaUltimoAbono);
            $data = $credito->toArray();
            unset($data['pagos']);

            $result[] = array_merge($data, [
                'saldo_actual' => round($saldo, 2),
                'monto_ultimo_abono' => round(min($saldo, $valorFicha), 2),
                'fecha_ultimo_abono' => $fecha->format('Y-m-d'),
                'dias_restantes' => (int) $hoy->diffInDays($fecha, false),
                'pago_semanal' => $valorFicha,
                'plazos' => $plazos,
                'pagos_restantes' => $pagosRestantes,
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

    public function cierreMensual(?string $fechaMes = null): array
    {
        $base = $fechaMes ? Carbon::parse($fechaMes)->startOfMonth() : now()->startOfMonth();
        $inicio = $base->copy()->startOfMonth();
        $fin = $base->copy()->endOfMonth();
        $resumenCaja = $this->flujoCajaService->resumen((int) $inicio->month, (int) $inicio->year);

        $carteraIndividual = $this->buildCarteraResumen('Individual');
        $carteraGrupal = $this->buildCarteraResumen('Grupal');
        $adeudos = $this->buildAdeudosResumen();
        $fuentes = $this->buildFuentesFondeoResumen($inicio, $fin);

        return [
            'mes' => $inicio->format('Y-m'),
            'inicio' => $inicio->toDateString(),
            'fin' => $fin->toDateString(),
            'flujo' => $resumenCaja,
            'cartera' => [
                'individual' => $carteraIndividual,
                'grupal' => $carteraGrupal,
                'adeudos' => $adeudos,
                'total_saldo' => round(
                    (float) $carteraIndividual['saldo_total']
                    + (float) $carteraGrupal['saldo_total']
                    + (float) $adeudos['saldo_total'],
                    2
                ),
            ],
            'fondeo' => $fuentes,
        ];
    }

    public function estadoFinancieroInversionistas(?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $inicio = $fechaInicio ? Carbon::parse($fechaInicio)->startOfDay() : now()->startOfMonth();
        $fin = $fechaFin ? Carbon::parse($fechaFin)->endOfDay() : now()->endOfMonth();
        $rendimientos = MovimientoCaja::query()
            ->where('categoria', 'Rendimiento')
            ->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();

        $inversionistas = Inversionista::with(['aportaciones' => fn ($q) => $q->orderBy('fecha')->orderBy('id')])
            ->orderBy('nombre')
            ->get()
            ->map(function (Inversionista $inv) use ($inicio, $fin, $rendimientos) {
                $aportacionesHistoricas = $inv->aportaciones->where('tipo', 'Aportacion')->sum('monto');
                $retirosHistoricos = $inv->aportaciones->where('tipo', 'Retiro')->sum('monto');
                $aportacionesPeriodo = $inv->aportaciones
                    ->filter(fn ($item) => $item->fecha && $item->fecha->between($inicio, $fin) && $item->tipo === 'Aportacion')
                    ->sum('monto');
                $retirosPeriodo = $inv->aportaciones
                    ->filter(fn ($item) => $item->fecha && $item->fecha->between($inicio, $fin) && $item->tipo === 'Retiro')
                    ->sum('monto');
                $rendimientosInv = $this->matchRendimientosToInversionista($rendimientos, $inv);

                $movimientos = $inv->aportaciones
                    ->filter(fn ($item) => $item->fecha && $item->fecha->between($inicio, $fin))
                    ->map(fn (Aportacion $item) => [
                        'fecha' => $item->fecha?->toDateString(),
                        'tipo' => $item->tipo,
                        'monto' => round((float) $item->monto, 2),
                        'descripcion' => $item->notas ?: ($item->tipo === 'Retiro' ? 'Retiro de capital' : 'Aportacion de capital'),
                    ])
                    ->concat($rendimientosInv->map(fn (MovimientoCaja $mov) => [
                        'fecha' => $mov->fecha?->toDateString(),
                        'tipo' => 'Rendimiento',
                        'monto' => round((float) $mov->monto, 2),
                        'descripcion' => $mov->motivo,
                    ]))
                    ->sortBy(fn (array $row) => sprintf('%s|%s', $row['fecha'] ?? '', $row['tipo'] ?? ''))
                    ->values()
                    ->all();

                return array_merge($inv->toArray(), [
                    'saldo_capital' => round((float) $aportacionesHistoricas - (float) $retirosHistoricos, 2),
                    'aportaciones_periodo' => round((float) $aportacionesPeriodo, 2),
                    'retiros_periodo' => round((float) $retirosPeriodo, 2),
                    'rendimientos_periodo' => round((float) $rendimientosInv->sum('monto'), 2),
                    'movimientos' => $movimientos,
                ]);
            })
            ->values();

        return [
            'inicio' => $inicio->toDateString(),
            'fin' => $fin->toDateString(),
            'resumen' => [
                'fuentes' => $inversionistas->count(),
                'saldo_capital' => round((float) $inversionistas->sum('saldo_capital'), 2),
                'aportaciones_periodo' => round((float) $inversionistas->sum('aportaciones_periodo'), 2),
                'retiros_periodo' => round((float) $inversionistas->sum('retiros_periodo'), 2),
                'rendimientos_periodo' => round((float) $inversionistas->sum('rendimientos_periodo'), 2),
            ],
            'inversionistas' => $inversionistas->all(),
        ];
    }

    public function carteraAhorro(): array
    {
        $personal = AhorroPersonal::with('asesor')->get()->map(function ($item) {
            return [
                'tipo_cartera' => 'personal',
                'id' => $item->id,
                'persona_id' => $item->asesor_id,
                'codigo' => $item->asesor?->id_asesor,
                'nombre' => $item->asesor?->nombre_asesor,
                'saldo' => round((float) $item->saldo, 2),
            ];
        });

        $socios = AhorroSocio::with('socio')->get()->map(function ($item) {
            return [
                'tipo_cartera' => 'socios',
                'id' => $item->id,
                'persona_id' => $item->socio_id,
                'codigo' => $item->socio?->codigo,
                'nombre' => $item->socio?->nombre,
                'saldo' => round((float) $item->saldo, 2),
            ];
        });

        return [
            'total_general' => round((float) ($personal->sum('saldo') + $socios->sum('saldo')), 2),
            'totales_por_tipo' => [
                'personal' => round((float) $personal->sum('saldo'), 2),
                'socios' => round((float) $socios->sum('saldo'), 2),
            ],
            'registros' => $personal->concat($socios)->sortBy(['tipo_cartera', 'nombre'])->values()->all(),
        ];
    }

    public function gastosOperativos(?string $inicio = null, ?string $fin = null, ?string $categoria = null, ?string $cuenta = null): array
    {
        $query = GastoOperativo::query()->orderByDesc('fecha')->orderByDesc('id');

        if ($inicio) {
            $query->whereDate('fecha', '>=', $inicio);
        }
        if ($fin) {
            $query->whereDate('fecha', '<=', $fin);
        }
        if ($categoria) {
            $query->where('categoria', $categoria);
        }
        if ($cuenta) {
            $query->where('cuenta', $cuenta);
        }

        $rows = $query->get()->map(function (GastoOperativo $gasto) {
            return [
                'id' => $gasto->id,
                'fecha' => $gasto->fecha?->toDateString(),
                'concepto' => $gasto->concepto,
                'categoria' => $gasto->categoria,
                'cuenta' => $gasto->cuenta,
                'monto' => round((float) $gasto->monto, 2),
            ];
        })->values();

        return [
            'filtros' => compact('inicio', 'fin', 'categoria', 'cuenta'),
            'total' => round((float) $rows->sum('monto'), 2),
            'registros' => $rows->all(),
            'categorias' => GastoOperativo::query()->whereNotNull('categoria')->distinct()->orderBy('categoria')->pluck('categoria')->values(),
            'cuentas' => GastoOperativo::query()->whereNotNull('cuenta')->distinct()->orderBy('cuenta')->pluck('cuenta')->values(),
        ];
    }

    public function reporteGestor(string $periodo, ?int $idAsesor = null, ?string $fecha = null): array
    {
        if ($periodo === 'diario') {
            return $this->reporteDiario($fecha, $idAsesor);
        }

        if ($periodo === 'semanal') {
            return $this->reporteSemanal($fecha, $idAsesor);
        }

        return $this->reporteMensual($fecha, $idAsesor);
    }

    public function clientesSinRenovacion(?int $idAsesor = null): array
    {
        $query = Credito::with(['cliente', 'grupo', 'asesor', 'pagos'])
            ->whereIn('estado', ['CerradoSinRenovacion', 'Finalizado'])
            ->where(function ($q) {
                $q->whereNotNull('ciclo_inicio_mora')
                    ->orWhere('dias_mora_cache', '>', 0);
            });

        if ($idAsesor) {
            $query->where('id_asesor', $idAsesor);
        }

        return $query->get()->map(function ($credito) {
            return array_merge($credito->toArray(), [
                'motivo' => 'Liquidado con antecedente de mora',
                'mora' => $this->moraService->calculate($credito),
            ]);
        })->values()->all();
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

    public function reporteSemanal(?string $semanaInicio = null, ?int $idAsesor = null): array
    {
        $inicio = $semanaInicio
            ? Carbon::parse($semanaInicio)->startOfWeek(Carbon::MONDAY)
            : now()->startOfWeek(Carbon::MONDAY);
        $fin = $inicio->copy()->addDays(5); // Lunes a Sábado

        $dias = [];
        for ($d = $inicio->copy(); $d->lte($fin); $d->addDay()) {
            $dias[] = $this->reporteDiario($d->format('Y-m-d'), $idAsesor);
        }

        return [
            'semana_inicio' => $inicio->format('Y-m-d'),
            'semana_fin' => $fin->format('Y-m-d'),
            'id_asesor' => $idAsesor,
            'dias' => $dias,
            'totales' => [
                'abonos' => round(collect($dias)->sum('total_abonos'), 2),
                'colocacion' => round(collect($dias)->sum('monto_colocado'), 2),
                'creditos' => collect($dias)->sum('creditos_otorgados'),
            ],
        ];
    }

    public function reporteMensual(?string $fechaMes = null, ?int $idAsesor = null): array
    {
        $base = $fechaMes ? Carbon::parse($fechaMes)->startOfMonth() : now()->startOfMonth();
        $inicio = $base->copy()->startOfMonth();
        $fin = $base->copy()->endOfMonth();

        $pagos = Pago::with(['credito.asesor'])
            ->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->when($idAsesor, fn ($q) => $q->whereHas('credito', fn ($c) => $c->where('id_asesor', $idAsesor)))
            ->get();

        $creditos = Credito::with(['asesor'])
            ->whereBetween('fecha_otorgacion', [$inicio->toDateString(), $fin->toDateString()])
            ->when($idAsesor, fn ($q) => $q->where('id_asesor', $idAsesor))
            ->get();

        $recepciones = RecepcionAsesor::query()
            ->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->when($idAsesor, fn ($q) => $q->where('id_asesor', $idAsesor))
            ->get();

        return [
            'mes' => $inicio->format('Y-m'),
            'inicio' => $inicio->toDateString(),
            'fin' => $fin->toDateString(),
            'id_asesor' => $idAsesor,
            'total_abonos' => round((float) $pagos->where('tipo', 'Abono')->sum('monto'), 2),
            'total_multas' => round((float) $pagos->where('tipo', 'Multa')->sum('monto'), 2),
            'total_recibido' => round((float) $recepciones->sum('monto_recibido'), 2),
            'creditos_otorgados' => $creditos->count(),
            'monto_colocado' => round((float) $creditos->sum('monto_otorgado'), 2),
            'por_gestor' => $this->buildResumenGestor($pagos, $creditos, $recepciones)->values()->all(),
        ];
    }

    private function buildResumenGestor($pagos, $creditos, $recepciones)
    {
        $idsGestores = $pagos
            ->map(fn ($pago) => (int) ($pago->credito?->id_asesor ?? 0))
            ->merge($creditos->pluck('id_asesor')->map(fn ($id) => (int) ($id ?? 0)))
            ->merge($recepciones->pluck('id_asesor')->map(fn ($id) => (int) ($id ?? 0)))
            ->unique()
            ->values();

        return $idsGestores->mapWithKeys(function ($asesorId) use ($pagos, $creditos, $recepciones) {
            $pagosGestor = $pagos->filter(fn ($pago) => (int) ($pago->credito?->id_asesor ?? 0) === (int) $asesorId);
            $creditosGestor = $creditos->where('id_asesor', (int) $asesorId);
            $recepcionesGestor = $recepciones->where('id_asesor', (int) $asesorId);
            $asesor = $pagosGestor->first()?->credito?->asesor ?? $creditosGestor->first()?->asesor;

            return [(string) $asesorId => [
                'id_asesor' => (int) $asesorId ?: null,
                'nombre_asesor' => $asesor?->nombre_asesor ?? 'Sin gestor',
                'codigo_asesor' => $asesor?->id_asesor,
                'abonos' => round((float) $pagosGestor->where('tipo', 'Abono')->sum('monto'), 2),
                'multas' => round((float) $pagosGestor->where('tipo', 'Multa')->sum('monto'), 2),
                'creditos_otorgados' => $creditosGestor->count(),
                'monto_colocado' => round((float) $creditosGestor->sum('monto_otorgado'), 2),
                'monto_recibido' => round((float) $recepcionesGestor->sum('monto_recibido'), 2),
            ]];
        })->sortByDesc('abonos');
    }

    private function buildPagosProgramados(Credito $credito): array
    {
        $schedule = $this->moraService->generateSchedule($credito);
        $pagos = [];

        for ($i = 0; $i < 16; $i++) {
            $pagos[] = round((float) ($schedule[$i]['pago'] ?? 0), 2);
        }

        return $pagos;
    }

    private function buildGroupMetrics(Credito $credito, float $saldoTotal): array
    {
        $meta = $this->extractGroupMeta($credito);
        $integrantes = collect($meta['integrantes'] ?? []);
        $ahorros = $this->normalizeSeries(
            $meta['ahorro_programado']
                ?? $meta['ahorros_programados']
                ?? $meta['ahorro_por_periodo']
                ?? $meta['ahorro_periodos']
                ?? []
        );

        $creditoTotal = (float) ($meta['credito_total_grupal'] ?? 0);
        if ($creditoTotal <= 0) {
            $creditoTotal = (float) ($integrantes->sum('total') ?: $credito->total ?: 0);
        }

        $saldoGrupal = (float) ($meta['saldo_grupal'] ?? 0);
        if ($saldoGrupal <= 0) {
            $saldoGrupal = (float) ($integrantes->sum('saldo_total') ?: $saldoTotal);
        }

        return [
            'credito_total_grupal' => round($creditoTotal, 2),
            'saldo_grupal' => round($saldoGrupal, 2),
            'ahorro_programado' => $ahorros,
            'ahorro_total_grupal' => round((float) ($meta['ahorro_total_grupal'] ?? array_sum($ahorros)), 2),
        ];
    }

    private function extractGroupMeta(Credito $credito): array
    {
        $tabla = $credito->tabla_amortizacion;
        if (is_array($tabla) && isset($tabla[0]) && is_array($tabla[0])) {
            return $tabla[0];
        }

        return [];
    }

    private function normalizeSeries(array $items, int $length = 16): array
    {
        $series = [];
        for ($i = 0; $i < $length; $i++) {
            $series[] = round((float) ($items[$i] ?? 0), 2);
        }

        return $series;
    }

    private function buildCarteraResumen(string $tipoCredito): array
    {
        $rows = Credito::query()
            ->where('tipo_credito', $tipoCredito)
            ->whereIn('estado', ['Activo', 'EnMora'])
            ->get();

        return [
            'creditos' => $rows->count(),
            'monto_otorgado' => round((float) $rows->sum('monto_otorgado'), 2),
            'saldo_total' => round((float) $rows->sum('saldo_pendiente'), 2),
        ];
    }

    private function buildAdeudosResumen(): array
    {
        $rows = Credito::query()
            ->where('estado', 'EnMora')
            ->get();

        return [
            'creditos' => $rows->count(),
            'monto_otorgado' => round((float) $rows->sum('monto_otorgado'), 2),
            'saldo_total' => round((float) $rows->sum('saldo_pendiente'), 2),
        ];
    }

    private function buildFuentesFondeoResumen(Carbon $inicio, Carbon $fin): array
    {
        $fuentes = Inversionista::with(['aportaciones' => fn ($q) => $q->orderBy('fecha')->orderBy('id')])
            ->orderBy('nombre')
            ->get()
            ->map(function (Inversionista $inv) use ($inicio, $fin) {
                $aportado = $inv->aportaciones->where('tipo', 'Aportacion')->sum('monto');
                $retirado = $inv->aportaciones->where('tipo', 'Retiro')->sum('monto');
                $aportacionesMes = $inv->aportaciones
                    ->filter(fn ($item) => $item->fecha && $item->fecha->between($inicio, $fin) && $item->tipo === 'Aportacion')
                    ->sum('monto');
                $retirosMes = $inv->aportaciones
                    ->filter(fn ($item) => $item->fecha && $item->fecha->between($inicio, $fin) && $item->tipo === 'Retiro')
                    ->sum('monto');

                return [
                    'id' => $inv->id,
                    'nombre' => $inv->nombre,
                    'tipo_entidad' => $inv->tipo_entidad ?: 'Persona Fisica',
                    'origen_fondeo' => $inv->origen_fondeo,
                    'saldo_capital' => round((float) $aportado - (float) $retirado, 2),
                    'aportaciones_mes' => round((float) $aportacionesMes, 2),
                    'retiros_mes' => round((float) $retirosMes, 2),
                    'es_fondeo_externo' => ($inv->tipo_entidad && $inv->tipo_entidad !== 'Persona Fisica')
                        || !empty($inv->origen_fondeo),
                ];
            })
            ->values();

        return [
            'total_fuentes' => $fuentes->count(),
            'capital_total' => round((float) $fuentes->sum('saldo_capital'), 2),
            'capital_externo' => round((float) $fuentes->where('es_fondeo_externo', true)->sum('saldo_capital'), 2),
            'aportaciones_mes' => round((float) $fuentes->sum('aportaciones_mes'), 2),
            'retiros_mes' => round((float) $fuentes->sum('retiros_mes'), 2),
            'fuentes' => $fuentes->all(),
        ];
    }

    private function matchRendimientosToInversionista($rendimientos, Inversionista $inversionista)
    {
        $needles = collect([
            $inversionista->nombre,
            $inversionista->origen_fondeo,
            "INV-{$inversionista->id}",
        ])
            ->filter(fn ($value) => !empty($value))
            ->map(fn ($value) => mb_strtoupper((string) $value))
            ->values();

        return $rendimientos->filter(function (MovimientoCaja $mov) use ($needles) {
            $haystack = mb_strtoupper(trim(implode(' ', [
                $mov->motivo,
                $mov->referencia,
                $mov->cuenta,
            ])));

            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($haystack, $needle)) {
                    return true;
                }
            }

            return false;
        })->values();
    }
}
