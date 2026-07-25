<?php

namespace App\Http\Controllers;

use App\Models\Credito;
use App\Models\Cliente;
use App\Models\Grupo;
use App\Http\Requests\StoreCreditoRequest;
use App\Http\Requests\UpdateCreditoRequest;
use App\Services\CicloService;
use App\Services\MoraCalculationService;
use Illuminate\Http\Request;

class CreditoController extends Controller
{
    public function __construct(
        private CicloService $cicloService,
        private MoraCalculationService $moraService
    ) {}

    public function index(Request $request)
    {
        $query = Credito::with(['cliente', 'grupo', 'asesor']);

        $user = auth()->user();
        if ($user && $user->role?->nombre === 'asesor' && $user->id_asesor) {
            $query->where('id_asesor', $user->id_asesor);
        }

        return response()->json($query->paginate($request->query('per_page', 10)));
    }

    public function store(StoreCreditoRequest $request)
    {
        $data = $request->validated();

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

            if ($grupo->es_socio_preferencial ?? false) {
                $data['es_personalizado'] = true;
                $data['porcentaje_interes'] = 0;
            }
        }

        $data['ciclo'] = $this->cicloService->calcularCiclo($data['id_cliente'] ?? null, $data['id_grupo'] ?? null);
        $data['comision_apertura'] = $data['comision_apertura'] ?? 100.00;
        $data['saldo_pendiente'] = $data['total'];
        $data['es_adicional'] = $esAdicional;

        $credito = Credito::create($data);
        $this->cicloService->registrarInicio($credito);

        return response()->json([
            'message' => 'Crédito creado exitosamente',
            'data' => $credito->load(['cliente', 'grupo', 'asesor']),
        ], 201);
    }

    public function show($id)
    {
        $credito = Credito::with([
            'cliente',
            'grupo.clientes',
            'grupo.asesor',
            'asesor',
            'pagos',
            'creditoPadre',
            'refinanciamientos',
        ])->findOrFail($id);
        $mora = $this->moraService->calculate($credito);

        return response()->json(array_merge($credito->toArray(), [
            'mora' => $mora,
            'dias_mora' => $mora['dias_mora'],
        ]));
    }

    public function update(UpdateCreditoRequest $request, $id)
    {
        $credito = Credito::findOrFail($id);
        $data = $request->validated();

        if (isset($data['id_cliente'])) {
            $cliente = Cliente::findOrFail($data['id_cliente']);
            $data['id_asesor'] = $cliente->id_asesor;
            $data['tipo_credito'] = 'Individual';
            $data['id_grupo'] = null;
        } elseif (isset($data['id_grupo'])) {
            $grupo = Grupo::findOrFail($data['id_grupo']);
            $data['id_asesor'] = $grupo->id_asesor;
            $data['tipo_credito'] = 'Grupal';
            $data['id_cliente'] = null;
        }

        $credito->update($data);

        if (isset($data['abono_recuperacion'])) {
            $this->moraService->syncCreditoState($credito->fresh()->load('pagos'));
        }

        return response()->json([
            'message' => 'Crédito actualizado exitosamente',
            'data' => $credito,
        ]);
    }

    public function destroy($id)
    {
        $credito = Credito::findOrFail($id);
        $credito->delete();
        return response()->json([
            'message' => 'Crédito eliminado exitosamente',
        ]);
    }
}
