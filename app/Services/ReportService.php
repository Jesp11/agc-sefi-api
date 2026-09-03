<?php

namespace App\Services;

use App\Models\AhorroEmpleado;
use App\Models\AhorroPersonal;
use App\Models\AhorroSocio;
use App\Models\Aportacion;
use App\Models\Asesor;
use App\Models\CierreMensualManual;
use App\Models\Cliente;
use App\Models\ConfiguracionSistema;
use App\Models\Credito;
use App\Models\GastoOperativo;
use App\Models\Inversionista;
use App\Models\MovimientoCaja;
use App\Models\Pago;
use App\Models\RecepcionAsesor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use SimpleXMLElement;
use ZipArchive;

class ReportService
{
    private const ACCIONISTAS_DEFAULT = [
        ['nombre' => 'JOSSUE GIBRAN S. DIAZ', 'porcentaje' => 0.43],
        ['nombre' => 'FREDY PONCE SANCHEZ', 'porcentaje' => 0.37],
        ['nombre' => 'JULIO CESAR RMZ. RAMOS', 'porcentaje' => 0.10],
        ['nombre' => 'GIBRAN URIEL SOBREVILLA', 'porcentaje' => 0.10],
    ];

    public function __construct(
        private MoraCalculationService $moraService,
        private FlujoCajaService $flujoCajaService,
        private IndicadoresOperativosService $indicadoresOperativosService,
    ) {}

    public function reporteDiario(?string $fecha = null, ?int $idAsesor = null): array
    {
        $fecha = $fecha ?? now()->toDateString();

        $pagosQuery = Pago::with(['credito.cliente', 'credito.grupo', 'credito.asesor'])
            ->whereDate('fecha', $fecha);

        $creditosQuery = Credito::with(['cliente', 'grupo', 'asesor'])
            ->whereDate('fecha_otorgacion', $fecha)
            ->where('estado', 'Activo');

        if ($idAsesor) {
            $pagosQuery->whereHas('credito', fn ($q) => $q->where('id_asesor', $idAsesor));
            $creditosQuery->where('id_asesor', $idAsesor);
        } else {
            // Vista admin: solo abonos (las multas son íntegras del asesor).
            $pagosQuery->where('tipo', 'Abono');
        }

        $pagos = $pagosQuery->get();
        $creditos = $creditosQuery->get();

        // Obtener cobranza programada (cuotas del día + atrasados) según día de la semana / amortización
        $cobrosDelDiaData = app(CarteraService::class)->cobrosDelDia($fecha, $idAsesor);
        $cobrosProgramados = collect($cobrosDelDiaData['cobros'] ?? [])
            ->filter(fn ($cobro) => ($cobro['estado'] ?? null) !== 'EnMora')
            ->values();

        $creditosEnMora = collect($cobrosDelDiaData['cobros'] ?? [])
            ->filter(fn ($cobro) => ($cobro['estado'] ?? null) === 'EnMora')
            ->pluck('num_prog')
            ->filter()
            ->unique()
            ->all();

        $pagos = $pagos->filter(function ($pago) use ($creditosEnMora) {
            $credito = $pago->credito;
            if (! $credito) {
                return false;
            }

            if ($credito->estado === 'EnMora') {
                return false;
            }

            return ! in_array($credito->num_prog, $creditosEnMora, true);
        })->values();

        $totalAbonosRegistrados = round((float) $pagos->where('tipo', 'Abono')->sum('monto'), 2);
        $montoColocado = round((float) $creditos->sum('monto_otorgado'), 2);

        $payload = [
            'fecha' => $fecha,
            'dia_semana' => $cobrosDelDiaData['dia_semana'] ?? null,
            'total_abonos' => $totalAbonosRegistrados,
            'total_programado_dia' => round((float) $cobrosProgramados->where('categoria', 'del_dia')->sum('monto_a_cobrar'), 2),
            'total_atrasado' => round((float) $cobrosProgramados->where('categoria', 'atrasado')->sum('monto_a_cobrar'), 2),
            'total_exigible' => round((float) $cobrosProgramados->sum('monto_a_cobrar'), 2),
            'creditos_otorgados' => $creditos->count(),
            'monto_colocado' => $montoColocado,
            'pagos' => $pagos->values(),
            'creditos' => $creditos,
            'cobros_programados' => $cobrosProgramados->values(),
        ];

        if ($idAsesor) {
            $payload['total_multas'] = round((float) $pagos->where('tipo', 'Multa')->sum('monto'), 2);
        } else {
            $asesorIds = collect()
                ->concat($pagos->pluck('credito.id_asesor'))
                ->concat($cobrosProgramados->pluck('asesor.id'))
                ->concat($creditos->pluck('id_asesor'))
                ->filter()
                ->unique()
                ->values();

            $asesoresModel = Asesor::whereIn('id', $asesorIds)->get()->keyBy('id');

            $porAsesor = [];
            foreach ($asesorIds as $aid) {
                $asesor = $asesoresModel->get((int) $aid);
                $pagosAsesor = $pagos->filter(fn ($p) => ($p->credito?->id_asesor ?? 0) === (int) $aid);
                $cobrosAsesor = $cobrosProgramados->filter(fn ($c) => ($c['asesor']['id'] ?? 0) === (int) $aid);
                $creditosAsesor = $creditos->where('id_asesor', (int) $aid);

                $cobrado = round((float) $pagosAsesor->sum('monto'), 2);
                $progDelDia = round((float) $cobrosAsesor->where('categoria', 'del_dia')->sum('monto_a_cobrar'), 2);
                $progAtrasado = round((float) $cobrosAsesor->where('categoria', 'atrasado')->sum('monto_a_cobrar'), 2);
                $progTotal = round((float) $cobrosAsesor->sum('monto_a_cobrar'), 2);

                $aRecibir = max($cobrado, $progTotal);

                $porAsesor[] = [
                    'id_asesor' => (int) $aid,
                    'nombre_asesor' => $asesor?->nombre_asesor ?? 'Sin asesor',
                    'codigo_asesor' => $asesor?->id_asesor,
                    'num_abonos' => $pagosAsesor->count(),
                    'total_cobrado' => $cobrado,
                    'prog_del_dia' => $progDelDia,
                    'prog_atrasado' => $progAtrasado,
                    'num_programados' => $cobrosAsesor->count(),
                    'num_del_dia' => $cobrosAsesor->where('categoria', 'del_dia')->count(),
                    'monto_programado' => $progTotal,
                    'monto_exigible' => $progTotal,
                    'a_recibir' => $aRecibir,
                    'creditos_otorgados' => $creditosAsesor->count(),
                    'monto_colocado' => round((float) $creditosAsesor->sum('monto_otorgado'), 2),
                    'clientes_programados' => $cobrosAsesor->values(),
                ];
            }

            $recepciones = RecepcionAsesor::whereDate('fecha', $fecha)
                ->get()
                ->keyBy('id_asesor');

            $isHistorical = $fecha <= '2026-08-31';

            $payload['por_asesor'] = collect($porAsesor)->map(function (array $row) use ($recepciones, $isHistorical) {
                $recepcion = $row['id_asesor'] ? $recepciones->get($row['id_asesor']) : null;
                $aRecibir = (float) $row['a_recibir'];

                if ($recepcion) {
                    $recibido = (float) $recepcion->monto_recibido;
                } elseif ($isHistorical && $row['total_cobrado'] > 0) {
                    $recibido = (float) $row['total_cobrado'];
                } else {
                    $recibido = null;
                }

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

            $totalEsperado = round((float) collect($payload['por_asesor'])->sum('a_recibir'), 2);
            $payload['total_a_recibir'] = $totalEsperado;
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
            $query->where(function ($q) {
                $q->where(function ($closed) {
                    $closed->whereIn('estado', ['CerradoSinRenovacion', 'Finalizado'])
                        ->where(function ($history) {
                            $history->whereNotNull('ciclo_inicio_mora')
                                ->orWhere('dias_mora_cache', '>', 0);
                        });
                })->orWhere(function ($imported) {
                    $imported->where('estado', 'EnMora')
                        ->where('tabla_amortizacion', 'like', '%"mora_clasificacion": "mora_muerta"%');
                });
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
            $query->where('estado', 'EnMora')
                ->where(function ($q) {
                    $q->where('tabla_amortizacion', 'not like', '%"mora_clasificacion": "mora_muerta"%')
                        ->orWhereNull('tabla_amortizacion');
                });
        } else {
            $query->where(function ($q) {
                $q->where(function ($closed) {
                        $closed->whereIn('estado', ['CerradoSinRenovacion', 'Finalizado'])
                            ->where(function ($history) {
                                $history->whereNotNull('ciclo_inicio_mora')
                                    ->orWhere('dias_mora_cache', '>', 0);
                            });
                    })->orWhere(function ($imported) {
                        $imported->where('estado', 'EnMora')
                            ->where('tabla_amortizacion', 'like', '%"mora_clasificacion": "mora_muerta"%');
                    });
            });
        }

        if ($idAsesor) {
            $query->where('id_asesor', $idAsesor);
        }

        $creditos = $query->get()->map(function ($credito) use ($tipo) {
            $mora = $this->moraService->calculate($credito);
            $saldoActual = round((float) ($mora['saldo_actual'] ?? $credito->saldo_pendiente ?? $credito->total), 2);
            $saldoInversion = $this->resolveSaldoInversion($credito, $saldoActual);

            $mora['saldo_inversion'] = $saldoInversion;

            return array_merge($credito->toArray(), [
                'mora' => $mora,
                'saldo_inversion' => $saldoInversion,
                'clasificacion_mora' => $tipo === 'mora-activa' ? 'mora_activa' : 'mora_muerta',
            ]);
        });

        return ['creditos' => $creditos->values(), 'total' => $creditos->count()];
    }

    private function resolveSaldoInversion(Credito $credito, float $saldoActual): float
    {
        $tabla = $credito->tabla_amortizacion;
        if (is_array($tabla) && $tabla !== []) {
            if (array_key_exists('saldo_inversion_importado', $tabla)) {
                return round((float) $tabla['saldo_inversion_importado'], 2);
            }
            if (array_key_exists('saldo_inversion_grupal', $tabla)) {
                return round((float) $tabla['saldo_inversion_grupal'], 2);
            }

            $first = $tabla[0] ?? null;
            if (is_array($first)) {
                if (array_key_exists('saldo_inversion_importado', $first)) {
                    return round((float) $first['saldo_inversion_importado'], 2);
                }
                if (array_key_exists('saldo_inversion_grupal', $first)) {
                    return round((float) $first['saldo_inversion_grupal'], 2);
                }
            }
        }

        return round($saldoActual - (float) ($credito->interes ?? 0), 2);
    }

    public function clientesPorCerrar(?int $idAsesor = null): array
    {
        $creditos = Credito::with(['cliente', 'grupo', 'asesor', 'pagos'])
            ->where('estado', 'Activo')
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

            if ($saldo <= 0 || !empty($mora['en_mora'])) {
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
            $fechaTermino = !empty($schedule) ? end($schedule)['fecha'] : null;
            $data = $credito->toArray();
            unset($data['pagos']);

            $result[] = array_merge($data, [
                'saldo_actual' => round($saldo, 2),
                'monto_ultimo_abono' => round(min($saldo, $valorFicha), 2),
                'fecha_ultimo_abono' => $fecha->format('Y-m-d'),
                'fecha_termino' => $fechaTermino,
                'fecha_programada_renovacion' => $credito->fecha_programada_renovacion,
                'renovacion_autorizada' => $credito->renovacion_autorizada ?? 'Pendiente',
                'renovacion_tasa' => $credito->renovacion_tasa ?? '',
                'dias_restantes' => (int) $hoy->diffInDays($fecha, false),
                'pago_semanal' => $valorFicha,
                'plazos' => $plazos,
                'pagos_restantes' => $pagosRestantes,
            ]);
        }

        usort($result, function ($a, $b) {
            $cmp = ($a['pagos_restantes'] ?? 0) <=> ($b['pagos_restantes'] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a['fecha_termino'] ?? '', $b['fecha_termino'] ?? '');
        });

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
        $corte = $this->resolveCorteMensual($inicio, $fin);
        $resumenCaja = $this->flujoCajaService->resumen((int) $inicio->month, (int) $inicio->year);

        $carteraIndividual = $this->buildCarteraResumen('Individual');
        $carteraGrupal = $this->buildCarteraResumen('Grupal');
        $adeudos = $this->buildAdeudosResumen();
        $fuentes = $this->buildFuentesFondeoResumen($inicio, $fin);
        $visual = $this->buildCierreMensualVisual($inicio, $corte);

        return [
            'mes' => $inicio->format('Y-m'),
            'inicio' => $inicio->toDateString(),
            'fin' => $fin->toDateString(),
            'corte' => $corte->toDateString(),
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
            'visual' => $visual,
        ];
    }

    public function guardarCierreMensualManual(string $mes, array $data): CierreMensualManual
    {
        Carbon::createFromFormat('Y-m', $mes);

        return CierreMensualManual::query()->updateOrCreate(
            ['mes' => $mes],
            [
                'aumento_cartera' => $data['aumento_cartera'] ?? null,
                'pase_a_cartera_mora' => $data['pase_a_cartera_mora'] ?? null,
                'productividad_mensual' => $data['productividad_mensual'] ?? null,
                'registrado_por' => Auth::id(),
            ]
        );
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

        $todosRendimientos = MovimientoCaja::query()
            ->where('categoria', 'Rendimiento')
            ->get();

        $carteraActivaTotal = (float) Credito::where('estado', 'Activo')->sum('saldo_pendiente');

        $configFondeo = [
            'MARIA GUADALUPE DIAZ RODRIGUEZ' => ['tasa' => 4.0, 'compromiso' => 2000.0, 'dia' => 'Día 15 de cada mes'],
            'JUANA SANCHEZ MORALES' => ['tasa' => 12.0, 'compromiso' => 2400.0, 'dia' => 'Días 24 ($800) y 28 ($1,600)'],
            'ISIDORA HERNANDEZ GARCIA 1' => ['tasa' => 4.0, 'compromiso' => 1600.0, 'dia' => 'Día 24 de cada mes'],
            'ISIDORA HERNANDEZ GARCIA 2' => ['tasa' => 4.0, 'compromiso' => 2000.0, 'dia' => 'Día 28 de cada mes'],
            'JOSSUE GIBRAN SOBREVILLA DIAZ 1' => ['tasa' => 5.0, 'compromiso' => 4250.0, 'dia' => 'Día 05 de cada mes'],
            'JOSSUE GIBRAN SOBREVILLA DIAZ 2' => ['tasa' => 2.0, 'compromiso' => 2000.0, 'dia' => 'Día 25 de cada mes'],
        ];

        $inversionistas = Inversionista::with(['aportaciones' => fn ($q) => $q->orderBy('fecha')->orderBy('id')])
            ->orderBy('nombre')
            ->get()
            ->map(function (Inversionista $inv) use ($inicio, $fin, $rendimientos, $todosRendimientos, $configFondeo) {
                $aportacionesHistoricas = (float) $inv->aportaciones->where('tipo', 'Aportacion')->sum('monto');
                $retirosHistoricos = (float) $inv->aportaciones->where('tipo', 'Retiro')->sum('monto');
                $saldoCapital = round($aportacionesHistoricas - $retirosHistoricos, 2);

                $aportacionesPeriodo = (float) $inv->aportaciones
                    ->filter(fn ($item) => $item->fecha && $item->fecha->between($inicio, $fin) && $item->tipo === 'Aportacion')
                    ->sum('monto');
                $retirosPeriodo = (float) $inv->aportaciones
                    ->filter(fn ($item) => $item->fecha && $item->fecha->between($inicio, $fin) && $item->tipo === 'Retiro')
                    ->sum('monto');

                $rendimientosInv = $this->matchRendimientosToInversionista($rendimientos, $inv);
                $todosRendimientosInv = $this->matchRendimientosToInversionista($todosRendimientos, $inv);

                $cfg = $configFondeo[$inv->nombre] ?? [
                    'tasa' => $saldoCapital > 0 ? round(($rendimientosInv->sum('monto') / $saldoCapital) * 100, 2) : 0,
                    'compromiso' => 0.0,
                    'dia' => 'No especificado',
                ];

                $movimientos = $inv->aportaciones
                    ->filter(fn ($item) => $item->fecha && $item->fecha->between($inicio, $fin))
                    ->map(fn (Aportacion $item) => [
                        'fecha' => $item->fecha?->toDateString(),
                        'tipo' => $item->tipo,
                        'monto' => round((float) $item->monto, 2),
                        'descripcion' => $item->notas ?: ($item->tipo === 'Retiro' ? 'Retiro de capital' : 'Aportación de capital'),
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
                    'saldo_capital' => $saldoCapital,
                    'aportaciones_periodo' => round($aportacionesPeriodo, 2),
                    'retiros_periodo' => round($retirosPeriodo, 2),
                    'rendimientos_periodo' => round((float) $rendimientosInv->sum('monto'), 2),
                    'rendimientos_historicos' => round((float) $todosRendimientosInv->sum('monto'), 2),
                    'tasa_mensual' => $cfg['tasa'],
                    'compromiso_mensual' => $cfg['compromiso'],
                    'dia_pago' => $cfg['dia'],
                    'movimientos' => $movimientos,
                ]);
            })
            ->values();

        $capitalTotal = (float) $inversionistas->sum('saldo_capital');
        $rendimientosPeriodoTotal = (float) $inversionistas->sum('rendimientos_periodo');
        $compromisoMensualTotal = (float) $inversionistas->sum('compromiso_mensual');
        $rendimientosHistoricosTotal = (float) $inversionistas->sum('rendimientos_historicos');
        $ratioCobertura = $capitalTotal > 0 ? round($carteraActivaTotal / $capitalTotal, 2) : 0;
        $tasaPonderada = $capitalTotal > 0 ? round(($compromisoMensualTotal / $capitalTotal) * 100, 2) : 0;

        return [
            'inicio' => $inicio->toDateString(),
            'fin' => $fin->toDateString(),
            'resumen' => [
                'fuentes' => $inversionistas->count(),
                'fuentes_activas' => $inversionistas->where('saldo_capital', '>', 0)->count(),
                'saldo_capital' => round($capitalTotal, 2),
                'aportaciones_periodo' => round((float) $inversionistas->sum('aportaciones_periodo'), 2),
                'retiros_periodo' => round((float) $inversionistas->sum('retiros_periodo'), 2),
                'rendimientos_periodo' => round($rendimientosPeriodoTotal, 2),
                'rendimientos_historicos' => round($rendimientosHistoricosTotal, 2),
                'compromiso_mensual_total' => round($compromisoMensualTotal, 2),
                'tasa_ponderada_mensual' => $tasaPonderada,
                'cartera_activa_total' => round($carteraActivaTotal, 2),
                'ratio_cobertura' => $ratioCobertura,
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

    private function resolveCorteMensual(Carbon $inicio, Carbon $fin): Carbon
    {
        $fechas = collect([
            Credito::query()
                ->whereDate('fecha_otorgacion', '>=', $inicio->toDateString())
                ->whereDate('fecha_otorgacion', '<=', $fin->toDateString())
                ->max('fecha_otorgacion'),
            Pago::query()
                ->whereDate('fecha', '>=', $inicio->toDateString())
                ->whereDate('fecha', '<=', $fin->toDateString())
                ->max('fecha'),
            MovimientoCaja::query()
                ->whereDate('fecha', '>=', $inicio->toDateString())
                ->whereDate('fecha', '<=', $fin->toDateString())
                ->max('fecha'),
            Aportacion::query()
                ->whereDate('fecha', '>=', $inicio->toDateString())
                ->whereDate('fecha', '<=', $fin->toDateString())
                ->max('fecha'),
        ])
            ->filter()
            ->map(fn ($fecha) => Carbon::parse($fecha)->startOfDay())
            ->sort()
            ->values();

        if ($fechas->isNotEmpty()) {
            return $fechas->last();
        }

        return $fin->copy()->startOfDay();
    }

    private function buildCierreMensualVisual(Carbon $inicio, Carbon $corte): array
    {
        $manual = $this->buildCierreMensualManual($inicio);
        $carteraIndWorkbook = $this->readCierreWorkbookValueByLabel($corte, 'CARTERA INDIVIDUAL');
        $carteraGrupWorkbook = $this->readCierreWorkbookValueByLabel($corte, 'CARTERA GRUPAL');

        $carteraIndividualActiva = $this->buildPortfolioSnapshot('Individual', 'Activo', $corte);
        if ($carteraIndWorkbook !== null) {
            $carteraIndividualActiva['saldo_total'] = $carteraIndWorkbook;
        }

        $carteraGrupalActiva = $this->buildPortfolioSnapshot('Grupal', 'Activo', $corte);
        if ($carteraGrupWorkbook !== null) {
            $carteraGrupalActiva['saldo_total'] = $carteraGrupWorkbook;
        }

        $capitalPasivo = $this->buildCapitalPasivo($corte);
        $valorBruto = round(
            (float) $carteraIndividualActiva['saldo_total']
            + (float) $carteraGrupalActiva['saldo_total']
            + (float) $capitalPasivo,
            2
        );

        $inversionistas = $this->buildInversionistasAgrupados($corte);
        $adeudoInversionistas = round((float) collect($inversionistas)->sum('saldo_capital'), 2);
        $adeudoMercadoPago = $this->buildMercadoPagoAdeudo($corte);
        $adeudosTotal = round($adeudoInversionistas + $adeudoMercadoPago, 2);
        $valorNeto = round($valorBruto - $adeudosTotal, 2);

        $accionistas = collect($this->accionistasConfigurados())->map(function (array $row) use ($valorBruto, $valorNeto, $adeudosTotal) {
            $porcentaje = (float) $row['porcentaje'];
            return [
                'nombre' => $row['nombre'],
                'porcentaje' => round($porcentaje * 100, 2),
                'valor_bruto' => round($valorBruto * $porcentaje, 2),
                'valor_neto' => round($valorNeto * $porcentaje, 2),
                'adeudo_asignado' => round($adeudosTotal * $porcentaje, 2),
            ];
        })->values()->all();

        $liquidaciones = $this->buildLiquidaciones($inicio, $corte);
        $indicadoresAutomaticos = $this->indicadoresOperativosService->resumenMensual($inicio, $corte);
        $mora = $this->buildMoraSnapshot($corte);
        $aumentoCartera = ((float) ($indicadoresAutomaticos['aumento_cartera'] ?? 0)) > 0
            ? $indicadoresAutomaticos['aumento_cartera']
            : $manual['aumento_cartera'];
        $paseMora = ((float) ($indicadoresAutomaticos['pase_a_cartera_mora'] ?? 0)) > 0
            ? $indicadoresAutomaticos['pase_a_cartera_mora']
            : $manual['pase_a_cartera_mora'];
        $productividadMensual = $aumentoCartera !== null
            ? round((float) $aumentoCartera + (float) $liquidaciones['monto'], 2)
            : $manual['productividad_mensual'];

        return [
            'titulo' => sprintf(
                'INFORME CIERRE MES DEL MES DE %s Y PROYECCIONES DE %s DE %s',
                mb_strtoupper($inicio->locale('es')->isoFormat('MMMM')),
                mb_strtoupper($inicio->copy()->addMonth()->locale('es')->isoFormat('MMMM')),
                $inicio->year
            ),
            'valores_acciones' => [
                'cartera_individual' => $carteraIndividualActiva['saldo_total'],
                'cartera_grupal' => $carteraGrupalActiva['saldo_total'],
                'capital_pasivo' => $capitalPasivo,
                'valor_bruto_cartera' => $valorBruto,
                'valor_neto_cartera' => $valorNeto,
            ],
            'adeudos_cartera' => [
                'inversionistas' => $adeudoInversionistas,
                'mercado_pago' => $adeudoMercadoPago,
                'total' => $adeudosTotal,
            ],
            'operacion' => [
                'aumento_cartera' => $aumentoCartera,
                'pase_a_cartera_mora' => $paseMora,
                'liquidaciones' => $liquidaciones['monto'],
                'productividad_mensual' => $productividadMensual,
                'notas' => [
                    'Liquidaciones en el cierre mensual representa el adeudo/liquidacion de Mercado Pago.',
                    'Productividad mensual se calcula como aumento de cartera + liquidaciones.',
                    'Aumento de cartera y pase a cartera de mora usan eventos operativos cuando existan; si no, se conserva la captura manual.',
                ],
                'detalle_eventos' => $indicadoresAutomaticos['eventos'],
            ],
            'captura_manual' => $manual,
            'accionistas' => [
                'porcentajes' => collect($accionistas)->map(fn (array $row) => [
                    'nombre' => $row['nombre'],
                    'porcentaje' => $row['porcentaje'],
                ])->all(),
                'valores' => $accionistas,
            ],
            'inversionistas' => [
                'registros' => $inversionistas,
                'total' => $adeudoInversionistas,
            ],
            'adeudos_por_accionista' => collect($accionistas)->map(fn (array $row) => [
                'nombre' => $row['nombre'],
                'monto' => $row['adeudo_asignado'],
            ])->all(),
            'cartera_individual' => [
                'clientes_activos' => $carteraIndividualActiva['creditos'],
            ],
            'cartera_grupal' => [
                'grupos_activos' => $carteraGrupalActiva['creditos'],
                'clientes_activos' => $this->countGrupalMembersAt($corte),
            ],
            'total_clientes' => (int) $carteraIndividualActiva['creditos'] + (int) $this->countGrupalMembersAt($corte),
            'distribucion_carteras' => $this->buildDistribucionCarteras($corte),
            'cierre_mora' => $mora,
            'liquidaciones_detalle' => $liquidaciones,
        ];
    }

    public function accionistasConfigurados(): array
    {
        $row = ConfiguracionSistema::query()
            ->where('clave', 'accionistas_participacion')
            ->first();

        if (!$row) {
            return self::ACCIONISTAS_DEFAULT;
        }

        $decoded = json_decode((string) $row->valor, true);
        if (!is_array($decoded)) {
            return self::ACCIONISTAS_DEFAULT;
        }

        $rows = collect($decoded)
            ->map(function ($item) {
                if (!is_array($item)) {
                    return null;
                }

                $nombre = trim((string) ($item['nombre'] ?? ''));
                $porcentaje = round((float) ($item['porcentaje'] ?? 0), 4);

                if ($nombre === '' || $porcentaje <= 0) {
                    return null;
                }

                return [
                    'nombre' => $nombre,
                    'porcentaje' => $porcentaje,
                ];
            })
            ->filter()
            ->values();

        if ($rows->isEmpty()) {
            return self::ACCIONISTAS_DEFAULT;
        }

        return $rows->all();
    }

    public function guardarAccionistasConfigurados(array $rows): array
    {
        $normalized = collect($rows)
            ->map(function (array $row) {
                return [
                    'nombre' => trim((string) ($row['nombre'] ?? '')),
                    'porcentaje' => round(((float) ($row['porcentaje'] ?? 0)) / 100, 4),
                ];
            })
            ->filter(fn (array $row) => $row['nombre'] !== '' && $row['porcentaje'] > 0)
            ->values();

        ConfiguracionSistema::query()->updateOrCreate(
            ['clave' => 'accionistas_participacion'],
            ['valor' => json_encode($normalized->all(), JSON_UNESCAPED_UNICODE)]
        );

        return $this->accionistasConfigurados();
    }

    private function buildPortfolioSnapshot(string $tipoCredito, string $estado, Carbon $corte): array
    {
        $rows = Credito::query()
            ->where('tipo_credito', $tipoCredito)
            ->where('estado', $estado)
            ->whereDate('fecha_otorgacion', '<=', $corte->toDateString())
            ->get();

        return [
            'creditos' => $rows->count(),
            'saldo_total' => round((float) $rows->sum('saldo_pendiente'), 2),
            'monto_otorgado' => round((float) $rows->sum('monto_otorgado'), 2),
        ];
    }

    private function cashBalanceAt(Carbon $corte): float
    {
        $saldo = MovimientoCaja::query()
            ->whereDate('fecha', '<=', $corte->toDateString())
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->value('saldo_resultante');

        return round((float) ($saldo ?? 0), 2);
    }

    private function buildCapitalPasivo(Carbon $corte): float
    {
        $workbookValue = $this->readFlujoWorkbookValueByLabel(
            $corte,
            'CAPITAL PASIVO'
        );

        if ($workbookValue !== null) {
            return $workbookValue;
        }

        return $this->cashBalanceAt($corte);
    }

    private function buildCierreMensualManual(Carbon $inicio): array
    {
        $row = CierreMensualManual::query()
            ->where('mes', $inicio->format('Y-m'))
            ->first();

        return [
            'mes' => $inicio->format('Y-m'),
            'aumento_cartera' => $this->nullableDecimal($row?->aumento_cartera),
            'pase_a_cartera_mora' => $this->nullableDecimal($row?->pase_a_cartera_mora),
            'productividad_mensual' => $this->nullableDecimal($row?->productividad_mensual),
            'actualizado_en' => $row?->updated_at?->toDateTimeString(),
        ];
    }

    private function buildMercadoPagoAdeudo(Carbon $corte): float
    {
        $workbookValue = $this->readMercadoPagoAdeudoFromWorkbook($corte);
        if ($workbookValue !== null) {
            return $workbookValue;
        }

        $rows = MovimientoCaja::query()
            ->whereDate('fecha', '<=', $corte->toDateString())
            ->where(function ($q) {
                $q->where('cuenta', 'like', '%Mercado Pago%')
                    ->orWhere('motivo', 'like', '%Mercado Pago%')
                    ->orWhere('referencia', 'like', '%Mercado Pago%');
            })
            ->get();

        return round((float) $rows->sum(function (MovimientoCaja $row) {
            return $row->tipo === 'Ingreso' ? $row->monto : -1 * (float) $row->monto;
        }), 2);
    }

    private function readMercadoPagoAdeudoFromWorkbook(Carbon $corte): ?float
    {
        return $this->readFlujoWorkbookValueByLabel($corte, 'MERCADO PAGO');
    }

    private function resolveFlujoWorkbookPath(Carbon $corte): ?string
    {
        $root = dirname(base_path());
        $year = $corte->year;
        $candidates = [
            "{$root}/scripts/Actualizados/INGRESOS Y EGRESOS GENERALES {$year}.xlsx",
            "{$root}/scripts/Actualizados/INGRESOS Y EGRESOS GENERALES {$year} ACTUALIZADO.xlsx",
            "{$root}/scripts/import-flujo-caja/INGRESOS Y EGRESOS GENERALES {$year} ACTUALIZADO.xlsx",
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function readFlujoWorkbookValueByLabel(Carbon $corte, string $label): ?float
    {
        $path = $this->resolveFlujoWorkbookPath($corte);
        if ($path === null) {
            return null;
        }

        try {
            $cells = $this->readWorkbookSheetCells(
                $path,
                mb_strtoupper($corte->locale('es')->isoFormat('MMMM'))
            );
        } catch (\Throwable $e) {
            Log::warning('No se pudo leer valor desde workbook mensual.', [
                'path' => $path,
                'mes' => $corte->format('Y-m'),
                'label' => $label,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($cells === []) {
            return null;
        }

        $label = mb_strtoupper(trim($label));
        foreach ($cells as $ref => $value) {
            if (mb_strtoupper(trim((string) $value)) !== $label) {
                continue;
            }

            $nextRef = $this->incrementExcelColumn($ref);
            if ($nextRef === null || !array_key_exists($nextRef, $cells)) {
                continue;
            }

            $numeric = $this->normalizeExcelNumber($cells[$nextRef]);
            if ($numeric !== null) {
                return $numeric;
            }
        }

        return null;
    }

    private function readWorkbookSheetCells(string $path, string $sheetName): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }

        try {
            $sharedStrings = $this->readWorkbookSharedStrings($zip);
            $sheetPath = $this->resolveWorkbookSheetPath($zip, $sheetName);
            if ($sheetPath === null) {
                return [];
            }

            $xml = $zip->getFromName($sheetPath);
            if ($xml === false) {
                return [];
            }

            $sheet = new SimpleXMLElement($xml);
            $sheet->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

            $cells = [];
            foreach ($sheet->xpath('//a:sheetData/a:row/a:c') ?: [] as $cell) {
                $ref = (string) ($cell['r'] ?? '');
                if ($ref === '') {
                    continue;
                }

                $type = (string) ($cell['t'] ?? '');
                if ($type === 'inlineStr') {
                    $value = trim((string) ($cell->is->t ?? ''));
                } else {
                    $value = (string) ($cell->v ?? '');
                    if ($type === 's' && $value !== '' && isset($sharedStrings[(int) $value])) {
                        $value = $sharedStrings[(int) $value];
                    }
                }

                $cells[$ref] = $value;
            }

            return $cells;
        } finally {
            $zip->close();
        }
    }

    private function readWorkbookSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $shared = new SimpleXMLElement($xml);
        $shared->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $values = [];
        foreach ($shared->xpath('//a:si') ?: [] as $item) {
            $item->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $parts = [];
            foreach ($item->xpath('.//a:t') ?: [] as $text) {
                $parts[] = (string) $text;
            }
            $values[] = implode('', $parts);
        }

        return $values;
    }

    private function resolveWorkbookSheetPath(ZipArchive $zip, string $sheetName): ?string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            return null;
        }

        $workbook = new SimpleXMLElement($workbookXml);
        $workbook->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $relId = null;
        foreach ($workbook->xpath('//a:sheets/a:sheet') ?: [] as $sheet) {
            if (mb_strtoupper(trim((string) ($sheet['name'] ?? ''))) === mb_strtoupper(trim($sheetName))) {
                $attributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $relId = (string) ($attributes['id'] ?? '');
                break;
            }
        }

        if ($relId === null || $relId === '') {
            return null;
        }

        $rels = new SimpleXMLElement($relsXml);
        $rels->registerXPathNamespace('pr', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($rels->xpath('//pr:Relationship') ?: [] as $relationship) {
            if ((string) ($relationship['Id'] ?? '') === $relId) {
                return 'xl/' . ltrim((string) ($relationship['Target'] ?? ''), '/');
            }
        }

        return null;
    }

    private function incrementExcelColumn(string $cellRef): ?string
    {
        if (!preg_match('/^([A-Z]+)(\d+)$/', $cellRef, $matches)) {
            return null;
        }

        $column = $matches[1];
        $row = $matches[2];
        $length = strlen($column);
        $carry = 1;

        for ($i = $length - 1; $i >= 0; $i--) {
            $code = ord($column[$i]) - 64 + $carry;
            if ($code > 26) {
                $column[$i] = 'A';
                $carry = 1;
            } else {
                $column[$i] = chr($code + 64);
                $carry = 0;
                break;
            }
        }

        if ($carry === 1) {
            $column = 'A' . $column;
        }

        return $column . $row;
    }

    private function normalizeExcelNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        $normalized = str_replace([',', '$', ' '], '', (string) $value);
        return is_numeric($normalized) ? round((float) $normalized, 2) : null;
    }

    private function nullableDecimal(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }

    private function buildInversionistasAgrupados(Carbon $corte): array
    {
        return Inversionista::with(['aportaciones' => fn ($q) => $q->orderBy('fecha')->orderBy('id')])
            ->where('activo', true)
            ->orderBy('nombre')
            ->get()
            ->groupBy(function (Inversionista $inv) {
                return preg_replace('/\s+\d+$/', '', trim((string) $inv->nombre)) ?: $inv->nombre;
            })
            ->map(function ($group, $baseName) use ($corte) {
                $saldo = $group->sum(function (Inversionista $inv) use ($corte) {
                    return $inv->aportaciones
                        ->filter(fn ($item) => $item->fecha && $item->fecha->lte($corte))
                        ->sum(function (Aportacion $item) {
                            return $item->tipo === 'Retiro' ? -1 * (float) $item->monto : (float) $item->monto;
                        });
                });

                return [
                    'nombre' => $this->formatInvestorLabel((string) $baseName),
                    'saldo_capital' => round((float) $saldo, 2),
                ];
            })
            ->filter(fn (array $row) => abs((float) $row['saldo_capital']) > 0.009)
            ->values()
            ->all();
    }

    private function formatInvestorLabel(string $name): string
    {
        return match (mb_strtoupper(trim($name))) {
            'MARIA GUADALUPE DIAZ RODRIGUEZ' => 'MARIA GPE. DIAZ RDGZ.',
            'JUANA SANCHEZ MORALES' => 'JUANA SANCHEZ MORALES',
            'ISIDORA HERNANDEZ GARCIA' => 'ISIDORA HDZ. GARCIA',
            'JOSSUE GIBRAN SOBREVILLA DIAZ' => 'JOSSUE GIBRAN S. DIAZ',
            default => $name,
        };
    }

    private function buildLiquidaciones(Carbon $inicio, Carbon $corte): array
    {
        $montoMercadoPago = $this->readLiquidacionMercadoPagoFromWorkbook($corte);
        if ($montoMercadoPago === null) {
            $montoMercadoPago = $this->readFlujoWorkbookValueByLabel($corte, 'MERCADO PAGO');
        }

        $rows = MovimientoCaja::query()
            ->whereBetween('fecha', [$inicio->toDateString(), $corte->toDateString()])
            ->where(function ($q) {
                $q->where('motivo', 'like', '%LIQUIDACION%')
                    ->orWhere('motivo', 'like', '%LIQUIDADO%');
            })
            ->orderBy('fecha')
            ->orderBy('id')
            ->get()
            ->map(fn (MovimientoCaja $row) => [
                'fecha' => $row->fecha?->toDateString(),
                'tipo' => $row->tipo,
                'motivo' => $row->motivo,
                'monto' => round((float) $row->monto, 2),
            ])
            ->values();

        return [
            'monto' => round((float) ($montoMercadoPago ?? 0), 2),
            'criterio' => 'mercado_pago',
            'movimientos' => $rows->all(),
        ];
    }

    private function readLiquidacionMercadoPagoFromWorkbook(Carbon $corte): ?float
    {
        $path = $this->resolveFlujoWorkbookPath($corte);
        if ($path === null) {
            return null;
        }

        try {
            $cells = $this->readWorkbookSheetCells(
                $path,
                mb_strtoupper($corte->locale('es')->isoFormat('MMMM'))
            );
        } catch (\Throwable $e) {
            Log::warning('No se pudo leer liquidacion de Mercado Pago desde workbook mensual.', [
                'path' => $path,
                'mes' => $corte->format('Y-m'),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $matches = [];
        foreach ($cells as $ref => $value) {
            if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $parts)) {
                continue;
            }

            if (mb_strtoupper(trim((string) $value)) !== 'MERCADO PAGO') {
                continue;
            }

            $nextRef = $this->incrementExcelColumn($ref);
            if ($nextRef === null || !array_key_exists($nextRef, $cells)) {
                continue;
            }

            $numeric = $this->normalizeExcelNumber($cells[$nextRef]);
            if ($numeric === null) {
                continue;
            }

            $matches[] = [
                'column' => $parts[1],
                'row' => (int) $parts[2],
                'value' => $numeric,
            ];
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, function (array $a, array $b) {
            return [$a['row'], $a['column']] <=> [$b['row'], $b['column']];
        });

        $summaryMatch = collect($matches)->first(fn (array $match) => $match['column'] === 'P');
        if ($summaryMatch) {
            return $summaryMatch['value'];
        }

        return $matches[count($matches) - 1]['value'];
    }

    private function buildMoraSnapshot(Carbon $corte): array
    {
        $rows = Credito::query()
            ->where('estado', 'EnMora')
            ->whereDate('fecha_otorgacion', '<=', $corte->toDateString())
            ->get();

        $moraMuerta = $rows->filter(fn (Credito $credito) => $this->isMoraMuerta($credito));
        $moraActiva = $rows->reject(fn (Credito $credito) => $this->isMoraMuerta($credito));

        return [
            'mora_activa' => round((float) $moraActiva->sum('saldo_pendiente'), 2),
            'mora_muerta' => round((float) $moraMuerta->sum('saldo_pendiente'), 2),
            'total' => round((float) $rows->sum('saldo_pendiente'), 2),
        ];
    }

    private function isMoraMuerta(Credito $credito): bool
    {
        $tabla = $credito->tabla_amortizacion;
        $json = is_string($tabla) ? $tabla : json_encode($tabla, JSON_UNESCAPED_UNICODE);
        return is_string($json) && str_contains($json, '"mora_clasificacion":"mora_muerta"')
            || is_string($json) && str_contains($json, '"mora_clasificacion": "mora_muerta"');
    }

    private function countGrupalMembersAt(Carbon $corte): int
    {
        $groupIds = Credito::query()
            ->where('tipo_credito', 'Grupal')
            ->where('estado', 'Activo')
            ->whereDate('fecha_otorgacion', '<=', $corte->toDateString())
            ->whereNotNull('id_grupo')
            ->pluck('id_grupo');

        if ($groupIds->isEmpty()) {
            return 0;
        }

        return (int) \DB::table('cliente_grupo')
            ->whereIn('id_grupo', $groupIds->all())
            ->distinct('id_cliente')
            ->count('id_cliente');
    }

    private function resolveCierreWorkbookPath(Carbon $corte): ?string
    {
        $root = dirname(base_path());
        $mesNombre = mb_strtoupper($corte->locale('es')->isoFormat('MMMM'));
        $year = $corte->year;

        $candidates = [
            "{$root}/scripts/Actualizados/CIERRE DE MES DE {$mesNombre}.xlsx",
            "{$root}/scripts/Actualizados/CIERRE DE MES DE {$mesNombre} {$year}.xlsx",
            "{$root}/scripts/Actualizados/CIERRE DE MES DE {$mesNombre} DE {$year}.xlsx",
            "{$root}/scripts/Actualizados/CIERRE {$mesNombre} {$year}.xlsx",
            "{$root}/scripts/Actualizados/CIERRE DE {$mesNombre}.xlsx",
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function readCierreWorkbookValueByLabel(Carbon $corte, string $label): ?float
    {
        $path = $this->resolveCierreWorkbookPath($corte);
        if ($path === null) {
            return null;
        }

        try {
            $sheetNames = ['CIERRE DE ' . mb_strtoupper($corte->locale('es')->isoFormat('MMMM')), 'CIERRE', 'Sheet1'];
            $cells = [];
            foreach ($sheetNames as $name) {
                $cells = $this->readWorkbookSheetCells($path, $name);
                if ($cells !== []) {
                    break;
                }
            }

            if ($cells === []) {
                $zip = new ZipArchive();
                if ($zip->open($path) === true) {
                    $workbookXml = $zip->getFromName('xl/workbook.xml');
                    if ($workbookXml !== false) {
                        $wb = new SimpleXMLElement($workbookXml);
                        $wb->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                        $firstSheet = ($wb->xpath('//a:sheets/a:sheet') ?: [])[0] ?? null;
                        if ($firstSheet) {
                            $name = (string) ($firstSheet['name'] ?? '');
                            $zip->close();
                            $cells = $this->readWorkbookSheetCells($path, $name);
                        } else {
                            $zip->close();
                        }
                    } else {
                        $zip->close();
                    }
                }
            }

            if ($cells === []) {
                return null;
            }

            $label = mb_strtoupper(trim($label));
            foreach ($cells as $ref => $value) {
                if (mb_strtoupper(trim((string) $value)) !== $label) {
                    continue;
                }

                $nextRef = $this->incrementExcelColumn($ref);
                if ($nextRef === null || !array_key_exists($nextRef, $cells)) {
                    continue;
                }

                $numeric = $this->normalizeExcelNumber($cells[$nextRef]);
                if ($numeric !== null) {
                    return $numeric;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo leer valor desde cierre workbook.', [
                'path' => $path,
                'mes' => $corte->format('Y-m'),
                'label' => $label,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function readDistribucionCarterasFromWorkbook(Carbon $corte): ?array
    {
        $path = $this->resolveCierreWorkbookPath($corte);
        if ($path === null) {
            return null;
        }

        try {
            $sheetNames = ['CIERRE DE ' . mb_strtoupper($corte->locale('es')->isoFormat('MMMM')), 'CIERRE', 'Sheet1'];
            $cells = [];
            foreach ($sheetNames as $name) {
                $cells = $this->readWorkbookSheetCells($path, $name);
                if ($cells !== []) {
                    break;
                }
            }

            if ($cells === []) {
                $zip = new ZipArchive();
                if ($zip->open($path) === true) {
                    $workbookXml = $zip->getFromName('xl/workbook.xml');
                    if ($workbookXml !== false) {
                        $wb = new SimpleXMLElement($workbookXml);
                        $wb->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                        $firstSheet = ($wb->xpath('//a:sheets/a:sheet') ?: [])[0] ?? null;
                        if ($firstSheet) {
                            $name = (string) ($firstSheet['name'] ?? '');
                            $zip->close();
                            $cells = $this->readWorkbookSheetCells($path, $name);
                        } else {
                            $zip->close();
                        }
                    } else {
                        $zip->close();
                    }
                }
            }

            if ($cells === []) {
                return null;
            }

            $distRow = null;
            $distCol = null;
            foreach ($cells as $r => $v) {
                if (trim(mb_strtoupper((string) $v)) === 'DISTRIBUCION DE CARTERAS') {
                    if (preg_match('/^([A-Z]+)(\d+)$/', $r, $m)) {
                        $distCol = $m[1];
                        $distRow = (int) $m[2];
                        break;
                    }
                }
            }

            if ($distRow === null || $distCol === null) {
                return null;
            }

            $headerRow = $distRow + 1;
            $colResp = $distCol;
            $colAntRef = $this->incrementExcelColumn($colResp . $headerRow);
            if ($colAntRef === null) {
                return null;
            }
            preg_match('/^([A-Z]+)(\d+)$/', $colAntRef, $m);
            $colAnt = $m[1];

            $colActRef = $this->incrementExcelColumn($colAnt . $headerRow);
            if ($colActRef === null) {
                return null;
            }
            preg_match('/^([A-Z]+)(\d+)$/', $colActRef, $m);
            $colAct = $m[1];

            $labelAnt = trim((string) ($cells[$colAnt . $headerRow] ?? 'CLIENT/ANT'));
            $labelAct = trim((string) ($cells[$colAct . $headerRow] ?? 'CLIENT/ACT'));

            $registros = [];
            $totalAnt = null;
            $totalAct = null;

            for ($row = $headerRow + 1; $row <= $headerRow + 25; $row++) {
                $resp = trim((string) ($cells[$colResp . $row] ?? ''));
                $antVal = $this->normalizeExcelNumber($cells[$colAnt . $row] ?? null);
                $actVal = $this->normalizeExcelNumber($cells[$colAct . $row] ?? null);

                if ($resp === '' && $antVal === null && $actVal === null) {
                    break;
                }

                if ($resp === '' && ($antVal !== null || $actVal !== null)) {
                    $totalAnt = (int) ($antVal ?? 0);
                    $totalAct = (int) ($actVal ?? 0);
                    break;
                }

                if ($resp !== '') {
                    $registros[] = [
                        'asesor' => $this->formatAccionistaLabel($resp),
                        'nombre' => $this->formatAccionistaLabel($resp),
                        'clientes_mes_anterior' => (int) ($antVal ?? 0),
                        'clientes_mes_actual' => (int) ($actVal ?? 0),
                        'clientes_individuales_activos' => (int) ($antVal ?? 0),
                        'clientes_totales' => (int) ($actVal ?? 0),
                    ];
                }
            }

            if ($registros === []) {
                return null;
            }

            $computedTotalAnt = (int) collect($registros)->sum('clientes_mes_anterior');
            $computedTotalAct = (int) collect($registros)->sum('clientes_mes_actual');

            return [
                'mes_anterior_label' => $labelAnt,
                'mes_actual_label' => $labelAct,
                'registros' => $registros,
                'total_mes_anterior' => $totalAnt ?? $computedTotalAnt,
                'total_mes_actual' => $totalAct ?? $computedTotalAct,
                'total_individuales' => $totalAnt ?? $computedTotalAnt,
                'total_clientes' => $totalAct ?? $computedTotalAct,
            ];
        } catch (\Throwable $e) {
            Log::warning('No se pudo leer distribucion de carteras desde workbook de cierre.', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildDistribucionCarteras(Carbon $corte): array
    {
        $fromWorkbook = $this->readDistribucionCarterasFromWorkbook($corte);
        if ($fromWorkbook !== null) {
            return $fromWorkbook;
        }

        $corteAnterior = $corte->copy()->startOfMonth()->subDay();
        $mesAnteriorShort = mb_strtoupper(substr($corteAnterior->locale('es')->isoFormat('MMM'), 0, 3));
        $mesActualShort = mb_strtoupper(substr($corte->locale('es')->isoFormat('MMM'), 0, 3));

        $mesAnteriorLabel = "CLIENT/{$mesAnteriorShort}";
        $mesActualLabel = "CLIENT/{$mesActualShort}";

        $individualesActual = Credito::query()
            ->selectRaw('id_asesor, COUNT(*) as total')
            ->where('tipo_credito', 'Individual')
            ->where('estado', 'Activo')
            ->whereDate('fecha_otorgacion', '<=', $corte->toDateString())
            ->groupBy('id_asesor')
            ->pluck('total', 'id_asesor');

        $individualesAnterior = Credito::query()
            ->selectRaw('id_asesor, COUNT(*) as total')
            ->where('tipo_credito', 'Individual')
            ->where('estado', 'Activo')
            ->whereDate('fecha_otorgacion', '<=', $corteAnterior->toDateString())
            ->groupBy('id_asesor')
            ->pluck('total', 'id_asesor');

        $asesorIds = collect($individualesActual->keys())
            ->merge($individualesAnterior->keys())
            ->filter()
            ->unique()
            ->values();

        $asesores = Asesor::query()
            ->whereIn('id', $asesorIds->all())
            ->orderBy('nombre_asesor')
            ->get();

        $rows = $asesores->map(function (Asesor $asesor) use ($individualesAnterior, $individualesActual) {
            $cantAnterior = (int) ($individualesAnterior[$asesor->id] ?? 0);
            $cantActual = (int) ($individualesActual[$asesor->id] ?? 0);

            return [
                'asesor' => $this->formatAccionistaLabel($asesor->nombre_asesor),
                'nombre' => $this->formatAccionistaLabel($asesor->nombre_asesor),
                'clientes_mes_anterior' => $cantAnterior,
                'clientes_mes_actual' => $cantActual,
                'clientes_individuales_activos' => $cantAnterior,
                'clientes_totales' => $cantActual,
            ];
        })->sortBy('asesor')->values();

        $totalAnterior = (int) $rows->sum('clientes_mes_anterior');
        $totalActual = (int) $rows->sum('clientes_mes_actual');

        return [
            'mes_anterior_label' => $mesAnteriorLabel,
            'mes_actual_label' => $mesActualLabel,
            'registros' => $rows->all(),
            'total_mes_anterior' => $totalAnterior,
            'total_mes_actual' => $totalActual,
            'total_individuales' => $totalAnterior,
            'total_clientes' => $totalActual,
        ];
    }

    private function formatAccionistaLabel(string $name): string
    {
        return match (mb_strtoupper(trim($name))) {
            'JOSSUE GIBRAN SOBREVILLA DIAZ' => 'JOSSUE GIBRAN S. DIAZ',
            'JULIO CESAR RAMIREZ RAMOS' => 'JULIO CESAR RAMIREZ RAMOS',
            'GIBRAN URIEL SOBREVILLA HERNANDEZ' => 'GIBRAN URIEL SOBREVILLA',
            'FREDY PONCE SANCHEZ' => 'FREDY PONCE SANCHEZ',
            default => $name,
        };
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

    public function reporteCumpleanos(int $mes, ?int $idAsesor = null): array
    {
        $mesesNombres = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];

        $currentYear = (int) date('Y');
        $currentMonth = (int) date('n');
        $currentDay = (int) date('j');

        $query = Cliente::with([
            'asesor:id,nombre_asesor',
            'creditos' => function ($q) {
                $q->select('num_prog', 'id_cliente', 'tipo_credito', 'monto_otorgado', 'saldo_pendiente', 'total', 'estado')
                  ->where('estado', 'Activo');
            },
            'grupos:id,nombre_grupo'
        ]);

        if ($idAsesor) {
            $query->where(function ($q) use ($idAsesor) {
                $q->where('id_asesor', $idAsesor)
                  ->orWhereHas('creditos', function ($cq) use ($idAsesor) {
                      $cq->where('id_asesor', $idAsesor);
                  });
            });
        }

        $allClientes = $query->get();

        $cumpleaneros = [];
        $cumplenHoy = 0;
        $porCumplir = 0;
        $cumplidos = 0;

        foreach ($allClientes as $cliente) {
            $dia = null;
            $mesCliente = null;
            $anioNac = null;

            // 1. Intentar desde fecha_nacimiento
            if (!empty($cliente->fecha_nacimiento)) {
                $fechaStr = (string) $cliente->fecha_nacimiento;
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $fechaStr, $matches)) {
                    $anioNac = (int) $matches[1];
                    $mesCliente = (int) $matches[2];
                    $dia = (int) $matches[3];
                }
            }

            // 2. Si no, intentar extraer de la CURP (posiciones 4-9: AAMMDD)
            if ((!$mesCliente || !$dia) && !empty($cliente->curp) && strlen(trim($cliente->curp)) >= 10) {
                $curp = strtoupper(trim($cliente->curp));
                $aa = substr($curp, 4, 2);
                $mm = substr($curp, 6, 2);
                $dd = substr($curp, 8, 2);

                if (is_numeric($aa) && is_numeric($mm) && is_numeric($dd)) {
                    $mesInt = (int) $mm;
                    $diaInt = (int) $dd;
                    if ($mesInt >= 1 && $mesInt <= 12 && $diaInt >= 1 && $diaInt <= 31) {
                        $mesCliente = $mesInt;
                        $dia = $diaInt;
                        $anio2Digitos = (int) $aa;
                        $anioNac = ($anio2Digitos > (int) date('y')) ? 1900 + $anio2Digitos : 2000 + $anio2Digitos;
                    }
                }
            }

            // Si coincide con el mes solicitado
            if ($mesCliente === $mes && $dia !== null) {
                $edad = $anioNac ? ($currentYear - $anioNac) : null;
                $esHoy = ($mes === $currentMonth && $dia === $currentDay);
                $yaPaso = ($mes < $currentMonth) || ($mes === $currentMonth && $dia < $currentDay);

                if ($mes === $currentMonth) {
                    if ($esHoy) {
                        $cumplenHoy++;
                    } elseif ($dia < $currentDay) {
                        $cumplidos++;
                    } else {
                        $porCumplir++;
                    }
                }

                $creditoActivo = $cliente->creditos->first();

                $cumpleaneros[] = [
                    'id_cliente' => $cliente->id_cliente,
                    'nombre_completo' => $cliente->nombre_completo,
                    'telefono' => $cliente->telefono ?? '',
                    'curp' => $cliente->curp ?? '',
                    'dia' => $dia,
                    'mes' => $mes,
                    'anio_nacimiento' => $anioNac,
                    'fecha_nacimiento' => $anioNac ? sprintf('%04d-%02d-%02d', $anioNac, $mes, $dia) : null,
                    'edad' => $edad,
                    'es_hoy' => $esHoy,
                    'ya_paso' => $yaPaso,
                    'dias_restantes' => ($mes === $currentMonth && $dia >= $currentDay) ? ($dia - $currentDay) : null,
                    'asesor' => $cliente->asesor ? [
                        'id' => $cliente->asesor->id,
                        'nombre_asesor' => $cliente->asesor->nombre_asesor,
                    ] : null,
                    'grupo' => $cliente->grupos->first()?->nombre_grupo ?? null,
                    'estatus_cliente' => $cliente->estatus ?? 'Activo',
                    'tiene_credito_activo' => $creditoActivo !== null,
                    'credito_activo' => $creditoActivo ? [
                        'num_prog' => $creditoActivo->num_prog,
                        'tipo' => $creditoActivo->tipo_credito,
                        'saldo_pendiente' => (float) ($creditoActivo->saldo_pendiente ?? $creditoActivo->total ?? 0),
                    ] : null,
                ];
            }
        }

        // Ordenar por día ascendente
        usort($cumpleaneros, fn ($a, $b) => $a['dia'] - $b['dia']);

        return [
            'mes' => $mes,
            'nombre_mes' => $mesesNombres[$mes] ?? '',
            'anio' => $currentYear,
            'total_cumpleaneros' => count($cumpleaneros),
            'cumplen_hoy' => $cumplenHoy,
            'por_cumplir' => $porCumplir,
            'cumplidos' => $cumplidos,
            'clientes' => $cumpleaneros,
        ];
    }
}
