<?php

namespace App\Http\Controllers;

use App\Models\Credito;
use App\Models\Cliente;
use App\Models\Grupo;
use App\Http\Requests\StoreCreditoRequest;
use App\Http\Requests\UpdateCreditoRequest;
use Illuminate\Http\Request;

class CreditoController extends Controller
{
    public function index()
    {
        $creditos = Credito::with(['cliente', 'grupo', 'asesor'])->paginate(10);
        return response()->json($creditos);
    }

    public function store(StoreCreditoRequest $request)
    {
        $data = $request->validated();
        
        if (!empty($data['id_cliente'])) {
            $cliente = Cliente::findOrFail($data['id_cliente']);
            
            // Validar si el cliente ya tiene un crédito activo (individual)
            $creditoActivo = Credito::where('id_cliente', $cliente->id_cliente)
                ->where('estado', 'Activo')
                ->exists();
                
            if ($creditoActivo) {
                return response()->json(['message' => 'El cliente ya cuenta con un crédito individual activo.'], 422);
            }

            // Validar si el cliente está en un grupo con crédito activo
            $grupoConCreditoActivo = $cliente->grupos()->whereHas('creditos', function($query) {
                $query->where('estado', 'Activo');
            })->exists();

            if ($grupoConCreditoActivo) {
                return response()->json(['message' => 'El cliente pertenece a un grupo que ya cuenta con un crédito activo.'], 422);
            }

            $data['id_asesor'] = $cliente->id_asesor;
            $data['tipo_credito'] = 'Individual';
            $data['id_grupo'] = null;
        } else {
            $grupo = Grupo::with('clientes')->findOrFail($data['id_grupo']);
            
            // Validar el grupo en sí
            $creditoGrupoActivo = Credito::where('id_grupo', $grupo->id)
                ->where('estado', 'Activo')
                ->exists();

            if ($creditoGrupoActivo) {
                return response()->json(['message' => 'Este grupo ya cuenta con un crédito activo.'], 422);
            }

            // Validar cada integrante del grupo
            foreach ($grupo->clientes as $integrante) {
                // Crédito individual del integrante
                if (Credito::where('id_cliente', $integrante->id_cliente)->where('estado', 'Activo')->exists()) {
                    return response()->json(['message' => "El integrante {$integrante->nombre_completo} ya cuenta con un crédito individual activo."], 422);
                }
                
                // Otros grupos del integrante con crédito activo
                $otroGrupoActivo = $integrante->grupos()
                    ->where('grupos.id', '!=', $grupo->id)
                    ->whereHas('creditos', function($query) {
                        $query->where('estado', 'Activo');
                    })->exists();

                if ($otroGrupoActivo) {
                    return response()->json(['message' => "El integrante {$integrante->nombre_completo} pertenece a otro grupo con crédito activo."], 422);
                }
            }

            $data['id_asesor'] = $grupo->id_asesor;
            $data['tipo_credito'] = 'Grupal';
            $data['id_cliente'] = null;
        }

        $data['ciclo'] = 0;

        $credito = Credito::create($data);
        return response()->json([
            'message' => 'Crédito creado exitosamente',
            'data' => $credito
        ], 201);
    }

    public function show($id)
    {
        $credito = Credito::with(['cliente', 'grupo', 'asesor'])->findOrFail($id);
        return response()->json($credito);
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
        return response()->json([
            'message' => 'Crédito actualizado exitosamente',
            'data' => $credito
        ]);
    }

    public function destroy($id)
    {
        $credito = Credito::findOrFail($id);
        $credito->delete();
        return response()->json([
            'message' => 'Crédito eliminado exitosamente'
        ]);
    }
}
