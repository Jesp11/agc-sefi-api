<?php

namespace App\Http\Controllers;

use App\Models\Grupo;
use App\Http\Requests\StoreGrupoRequest;
use App\Http\Requests\UpdateGrupoRequest;
use Illuminate\Http\Request;

class GrupoController extends Controller
{
    public function index()
    {
        $grupos = Grupo::with(['clientes', 'asesor', 'creditos'])->paginate(10);
        return response()->json($grupos);
    }

    public function store(StoreGrupoRequest $request)
    {
        $data = $request->validated();
        $grupo = Grupo::create([
            'nombre_grupo' => $data['nombre_grupo'],
            'id_asesor' => $data['id_asesor'],
        ]);

        if (isset($data['clientes'])) {
            $grupo->clientes()->attach($data['clientes']);
        }

        return response()->json([
            'message' => 'Grupo creado exitosamente',
            'data' => $grupo->load('clientes')
        ], 201);
    }

    public function show($id)
    {
        $grupo = Grupo::with(['clientes', 'asesor', 'creditos'])->findOrFail($id);
        return response()->json($grupo);
    }

    public function update(UpdateGrupoRequest $request, $id)
    {
        $grupo = Grupo::findOrFail($id);
        $data = $request->validated();

        $grupo->update($data);

        if (isset($data['clientes'])) {
            $grupo->clientes()->sync($data['clientes']);
        }

        return response()->json([
            'message' => 'Grupo actualizado exitosamente',
            'data' => $grupo->load('clientes')
        ]);
    }

    public function destroy($id)
    {
        $grupo = Grupo::findOrFail($id);
        $grupo->delete();
        return response()->json([
            'message' => 'Grupo eliminado exitosamente'
        ]);
    }

    public function agregarCliente(Request $request, $id)
    {
        $request->validate([
            'id_cliente' => 'required|string|exists:clientes,id_cliente'
        ]);

        $grupo = Grupo::findOrFail($id);
        $id_cliente = $request->id_cliente;

        // Verificar si el grupo tiene un crédito activo
        $grupoTieneCreditoActivo = $grupo->creditos()->where('estado', 'Activo')->exists();

        if ($grupoTieneCreditoActivo) {
            // Si el grupo tiene crédito activo, el cliente no debe tener otros créditos activos
            $cliente = \App\Models\Cliente::find($id_cliente);
            
            $tieneCreditoIndividual = \App\Models\Credito::where('id_cliente', $id_cliente)
                ->where('estado', 'Activo')->exists();
            
            $tieneOtroGrupoActivo = $cliente->grupos()
                ->where('grupos.id', '!=', $grupo->id)
                ->whereHas('creditos', function($query) {
                    $query->where('estado', 'Activo');
                })->exists();

            if ($tieneCreditoIndividual || $tieneOtroGrupoActivo) {
                return response()->json(['message' => 'El cliente ya cuenta con otro crédito activo y no puede unirse a un grupo con crédito vigente.'], 422);
            }
        }

        $grupo->clientes()->syncWithoutDetaching([$id_cliente]);

        return response()->json([
            'message' => 'Cliente añadido al grupo exitosamente',
            'data' => $grupo->load('clientes')
        ]);
    }

    public function quitarCliente(Request $request, $id)
    {
        $request->validate([
            'id_cliente' => 'required|string|exists:clientes,id_cliente'
        ]);

        $grupo = Grupo::findOrFail($id);
        $grupo->clientes()->detach($request->id_cliente);

        return response()->json([
            'message' => 'Cliente eliminado del grupo exitosamente',
            'data' => $grupo->load('clientes')
        ]);
    }
}
