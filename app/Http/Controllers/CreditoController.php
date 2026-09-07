<?php

namespace App\Http\Controllers;

use App\Models\Credito;
use App\Models\Cliente;
use App\Models\Grupo;
use App\Support\RoleHelper;
use App\Http\Requests\StoreCreditoRequest;
use App\Http\Requests\UpdateCreditoRequest;
use App\Services\CicloService;
use App\Services\CreditoEliminacionBloqueadaException;
use App\Services\CreditoEliminacionDesactualizadaException;
use App\Services\CreditoEliminacionService;
use App\Services\DistribucionCreditoGrupalService;
use App\Services\FlujoCajaService;
use App\Services\MoraCalculationService;
use App\Services\RefinanciamientoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditoController extends Controller
{
    public function __construct(
        private CicloService $cicloService,
        private FlujoCajaService $flujoCajaService,
        private MoraCalculationService $moraService,
        private CreditoEliminacionService $creditoEliminacionService,
        private DistribucionCreditoGrupalService $distribucionService,
        private RefinanciamientoService $refinanciamientoService,
    ) {}

    public function index(Request $request)
    {
        $query = Credito::with(['cliente', 'grupo', 'asesor']);

        $user = auth()->user();
        if ($user && RoleHelper::isFieldLike($user->role?->nombre) && $user->id_asesor) {
            $query->where('id_asesor', $user->id_asesor);
        }

        return response()->json($query->paginate($request->query('per_page', 10)));
    }

    public function store(StoreCreditoRequest $request)
    {
        $data = $request->validated();
        $distribucionIntegrantes = $data['distribucion_integrantes'] ?? null;
        unset($data['distribucion_integrantes']);
        $comisionApertura = 100.00;

        $esPersonalizado = !empty($data['es_personalizado']);
        $esAdicional = !empty($data['es_adicional']);

        if (!empty($data['id_cliente'])) {
            $cliente = Cliente::findOrFail($data['id_cliente']);

            if (!$esPersonalizado && !$esAdicional) {
                $creditoActivo = Credito::where('id_cliente', $cliente->id_cliente)
                    ->whereIn('estado', ['Activo', 'EnMora'])
                    ->where('es_adicional', false)
                    ->exists();

                if ($creditoActivo) {
                    return response()->json(['message' => 'El cliente ya cuenta con un crédito individual activo.'], 422);
                }

                $grupoConCreditoActivo = $cliente->grupos()->whereHas('creditos', function ($query) {
                    $query->whereIn('estado', ['Activo', 'EnMora'])->where('es_adicional', false);
                })->exists();

                if ($grupoConCreditoActivo) {
                    return response()->json(['message' => 'El cliente pertenece a un grupo que ya cuenta con un crédito activo.'], 422);
                }
            }

            $data['id_asesor'] = $cliente->id_asesor;
            $data['tipo_credito'] = 'Individual';
            $data['id_grupo'] = null;

            if ($cliente->es_socio_preferencial) {
                $data['es_personalizado'] = true;
                $data['porcentaje_interes'] = 0;
            }
        } else {
            $grupo = Grupo::with('clientes')->findOrFail($data['id_grupo']);

            if (!$esPersonalizado && !$esAdicional) {
                $creditoGrupoActivo = Credito::where('id_grupo', $grupo->id)
                    ->whereIn('estado', ['Activo', 'EnMora'])
                    ->where('es_adicional', false)
                    ->exists();

                if ($creditoGrupoActivo) {
                    return response()->json(['message' => 'Este grupo ya cuenta con un crédito activo.'], 422);
                }

                foreach ($grupo->clientes as $integrante) {
                    if (Credito::where('id_cliente', $integrante->id_cliente)->whereIn('estado', ['Activo', 'EnMora'])->where('es_adicional', false)->exists()) {
                        return response()->json(['message' => "El integrante {$integrante->nombre_completo} ya cuenta con un crédito individual activo."], 422);
                    }

                    $otroGrupoActivo = $integrante->grupos()
                        ->where('grupos.id', '!=', $grupo->id)
                        ->whereHas('creditos', function ($query) {
                            $query->whereIn('estado', ['Activo', 'EnMora'])->where('es_adicional', false);
                        })->exists();

                    if ($otroGrupoActivo) {
                        return response()->json(['message' => "El integrante {$integrante->nombre_completo} pertenece a otro grupo con crédito activo."], 422);
                    }
                }
            }

            $data['id_asesor'] = $grupo->id_asesor;
            $data['tipo_credito'] = 'Grupal';
            $data['id_cliente'] = null;
            // En créditos grupales la comisión es $100 por integrante, no
            // una sola comisión para todo el grupo.
            $comisionApertura = round($grupo->clientes->count() * 100, 2);

            if ($grupo->es_socio_preferencial ?? false) {
                $data['es_personalizado'] = true;
                $data['porcentaje_interes'] = 0;
            }
        }

        $data['ciclo'] = $this->cicloService->calcularCiclo($data['id_cliente'] ?? null, $data['id_grupo'] ?? null);
        $data['comision_apertura'] = $data['tipo_credito'] === 'Grupal'
            ? $comisionApertura
            : ($data['comision_apertura'] ?? $comisionApertura);
        $data['saldo_pendiente'] = $data['total'];
        $data['es_adicional'] = $esAdicional;

        $credito = DB::transaction(function () use ($data, $distribucionIntegrantes) {
            $credito = Credito::create($data);
            if ($credito->tipo_credito === 'Grupal') {
                $this->distribucionService->guardar($credito, $distribucionIntegrantes ?? []);
                $credito->load('grupo.clientes');
                foreach ($credito->grupo?->clientes ?? [] as $integrante) {
                    $integrante->update(['estatus' => 'Activo', 'fecha_cierre' => null]);
                }
            } elseif ($credito->id_cliente) {
                Cliente::where('id_cliente', $credito->id_cliente)->update([
                    'estatus' => 'Activo',
                    'fecha_cierre' => null,
                ]);
            }
            return $credito;
        });
        $this->cicloService->registrarInicio($credito);
        $this->flujoCajaService->registrarDesdeDesembolso($credito, $this->montoNetoDesembolsado($credito));

        return response()->json([
            'message' => 'Crédito creado exitosamente',
            'data' => $credito->load(['cliente', 'grupo', 'asesor', 'distribucionesIntegrantes.cliente']),
        ], 201);
    }

    public function show($id)
    {
        $credito = Credito::with([
            'documentos.usuario',
            'cliente.avales',
            'cliente.documentos',
            'cliente.referencias',
            'grupo.clientes',
            'distribucionesIntegrantes.cliente',
            'grupo.asesor',
            'asesor',
            'pagos',
            'creditoPadre',
            'refinanciamientos.creditoAnterior',
            'refinanciamientosComoAnterior.creditoNuevo',
        ])->findOrFail($id);
        $mora = $this->moraService->calculate($credito);

        return response()->json(array_merge($credito->toArray(), [
            'mora' => $mora,
            'dias_mora' => $mora['dias_mora'],
            'distribucion_documental' => $this->distribucionService->resumen($credito),
        ]));
    }

    public function actualizarDistribucion(Request $request, $id)
    {
        $data = $request->validate([
            'integrantes' => ['required', 'array', 'min:1'],
            'integrantes.*.id_cliente' => ['required', 'string', 'exists:clientes,id_cliente'],
            'integrantes.*.capital' => ['required', 'numeric', 'gt:0'],
        ]);
        $credito = Credito::findOrFail($id);
        $this->distribucionService->guardar($credito, $data['integrantes']);
        $credito->load(['distribucionesIntegrantes.cliente', 'grupo.clientes']);

        return response()->json([
            'message' => 'Distribución documental guardada. Los movimientos y pagos del grupo no fueron modificados.',
            'data' => $credito->distribucionesIntegrantes,
            'distribucion_documental' => $this->distribucionService->resumen($credito),
        ]);
    }

    public function update(UpdateCreditoRequest $request, $id)
    {
        $credito = Credito::findOrFail($id);
        $data = $request->validated();

        // Compatibilidad con instalaciones que aún tienen caché de rutas: la
        // misma actualización estándar acepta la distribución documental.
        if (array_key_exists('distribucion_integrantes', $data)) {
            $this->distribucionService->guardar($credito, $data['distribucion_integrantes']);
            $credito->load(['distribucionesIntegrantes.cliente', 'grupo.clientes']);

            return response()->json([
                'message' => 'Distribución documental guardada. Los movimientos y pagos del grupo no fueron modificados.',
                'data' => $credito->distribucionesIntegrantes,
                'distribucion_documental' => $this->distribucionService->resumen($credito),
            ]);
        }

        // Permite reparar de forma explícita renovaciones históricas cuyo
        // efectivo neto o egreso se haya guardado con una cifra anterior.
        // No se actualiza el contrato, pagos ni saldo pendiente.
        if (!empty($data['sincronizar_refinanciamiento'])) {
            $montoNeto = $this->refinanciamientoService->sincronizarMontoEntregado($credito);

            if ($montoNeto === null) {
                return response()->json([
                    'message' => 'Este crédito no proviene de una refinanciación.',
                ], 422);
            }

            return response()->json([
                'message' => 'Efectivo neto y movimiento de egreso sincronizados.',
                'data' => [
                    'num_prog' => $credito->num_prog,
                    'monto_neto' => $montoNeto,
                ],
            ]);
        }

        $montoOtorgadoAnterior = (float) $credito->monto_otorgado;
        $comisionAperturaAnterior = (float) ($credito->comision_apertura ?? 0);
        $refinanciamiento = $credito->refinanciamientos()->first();

        if (isset($data['id_cliente'])) {
            $cliente = Cliente::findOrFail($data['id_cliente']);
            // Al crear un crédito se toma el asesor del cliente. En una
            // edición, un asesor enviado explícitamente es el responsable
            // asignado al crédito y no debe ser reemplazado aquí.
            $data['id_asesor'] = $data['id_asesor'] ?? $cliente->id_asesor;
            $data['tipo_credito'] = 'Individual';
            $data['id_grupo'] = null;
        } elseif (isset($data['id_grupo'])) {
            $grupo = Grupo::findOrFail($data['id_grupo']);
            $data['id_asesor'] = $data['id_asesor'] ?? $grupo->id_asesor;
            $data['tipo_credito'] = 'Grupal';
            $data['id_cliente'] = null;
            $data['comision_apertura'] = round($grupo->clientes()->count() * 100, 2);
        } elseif ($credito->tipo_credito === 'Grupal') {
            // También protege actualizaciones parciales hechas por API: el
            // importe se vuelve a obtener de los integrantes vigentes.
            $data['comision_apertura'] = round($credito->grupo()->first()?->clientes()->count() * 100, 2);
        }

        if ($refinanciamiento && (array_key_exists('monto_otorgado', $data) || array_key_exists('comision_apertura', $data))) {
            $montoNuevo = (float) ($data['monto_otorgado'] ?? $credito->monto_otorgado);
            $comisionNueva = (float) ($data['comision_apertura'] ?? ($credito->comision_apertura ?? 0));
            $minimo = round((float) $refinanciamiento->deduccion + $comisionNueva, 2);
            if ($montoNuevo + 0.004 < $minimo) {
                return response()->json([
                    'message' => 'El monto otorgado no puede ser menor al saldo absorbido más la comisión de apertura.',
                ], 422);
            }
        }

        DB::transaction(function () use ($credito, $data, $montoOtorgadoAnterior, $comisionAperturaAnterior, $refinanciamiento) {
            $credito->update($data);

            $montoOtorgadoCambio = array_key_exists('monto_otorgado', $data)
                && abs($montoOtorgadoAnterior - (float) $credito->monto_otorgado) >= 0.005;
            $comisionAperturaCambio = array_key_exists('comision_apertura', $data)
                && abs($comisionAperturaAnterior - (float) ($credito->comision_apertura ?? 0)) >= 0.005;
            $montoNetoDesembolsado = $this->montoNetoDesembolsado($credito);

            if ($refinanciamiento) {
                // En una renovación el efectivo entregado no es el monto bruto:
                // conserva la deducción del saldo absorbido y actualiza a la vez
                // refinanciamiento.monto_neto y su egreso DESEMBOLSO-{folio}.
                $this->refinanciamientoService->sincronizarMontoEntregado($credito);
            } elseif ($montoOtorgadoCambio || $comisionAperturaCambio) {
                $this->flujoCajaService->sincronizarDesembolso($credito, $montoNetoDesembolsado);
            } else {
                // Al guardar un crédito histórico, crea el egreso que faltaba.
                // registrarDesdeDesembolso es idempotente por referencia, por lo
                // que un movimiento existente no se duplica ni se modifica.
                $this->flujoCajaService->registrarDesdeDesembolso($credito, $montoNetoDesembolsado);
            }
        });

        if (isset($data['abono_recuperacion'])) {
            $this->moraService->syncCreditoState($credito->fresh()->load('pagos'));
        }

        return response()->json([
            'message' => 'Crédito actualizado exitosamente',
            'data' => $credito,
        ]);
    }

    public function eliminacionPreview($id)
    {
        $credito = Credito::findOrFail($id);

        return response()->json($this->creditoEliminacionService->preview($credito));
    }

    public function destroy(Request $request, $id)
    {
        $credito = Credito::findOrFail($id);
        $data = $request->validate([
            'confirmacion_folio' => ['required', 'string'],
            'huella_preview' => ['required', 'string', 'size:64'],
        ]);

        if (trim($data['confirmacion_folio']) !== (string) $credito->num_prog) {
            return response()->json([
                'message' => 'Escribe exactamente el folio del crédito para confirmar la eliminación.',
            ], 422);
        }

        try {
            $resultado = $this->creditoEliminacionService->eliminar(
                (int) $credito->num_prog,
                $data['huella_preview'],
            );
        } catch (CreditoEliminacionBloqueadaException|CreditoEliminacionDesactualizadaException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Crédito eliminado y sus efectos vinculados fueron revertidos.',
            'data' => [
                'num_prog' => $credito->num_prog,
                'documentos_eliminados' => $resultado['documentos_eliminados'],
            ],
        ]);
    }

    private function montoNetoDesembolsado(Credito $credito): float
    {
        return max(0, (float) $credito->monto_otorgado - (float) ($credito->comision_apertura ?? 0));
    }
}
