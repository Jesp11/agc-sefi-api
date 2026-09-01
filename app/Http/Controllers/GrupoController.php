<?php

namespace App\Http\Controllers;

use App\Models\Grupo;
use App\Http\Requests\StoreGrupoRequest;
use App\Http\Requests\UpdateGrupoRequest;
use Illuminate\Http\Request;

class GrupoController extends Controller
{
    public function index(Request $request)
    {
        $grupos = Grupo::with(['clientes', 'asesor', 'creditos'])
            ->paginate($request->query('per_page', 10));
        return response()->json($grupos);
    }

    public function export()
    {
        $grupos = Grupo::with(['asesor', 'clientes'])->orderBy('nombre_grupo')->get();

        $data = $grupos->map(function (Grupo $g) {
            return [
                'id' => $g->id,
                'nombre_grupo' => $g->nombre_grupo,
                'nombre_asesor' => $g->asesor?->nombre_asesor ?? '',
                'total_integrantes' => $g->clientes->count(),
                'integrantes' => $g->clientes->pluck('nombre_completo')->implode(', '),
                'es_socio_preferencial' => $g->es_socio_preferencial ? 'Sí' : 'No',
                'created_at' => $g->created_at,
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function import(Request $request)
    {
        $request->validate([
            'grupos' => 'required|array|min:1',
            'grupos.*.nombre_grupo' => 'required|string|max:255',
            'grupos.*.nombre_asesor' => 'nullable|string|max:255',
            'grupos.*.id_asesor' => 'nullable|integer|exists:asesores,id',
            'grupos.*.es_socio_preferencial' => 'nullable',
            'grupos.*.integrantes' => 'nullable|string',
        ]);

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($request->input('grupos') as $index => $row) {
            $rowNumber = $index + 2;
            $nombreGrupo = trim($row['nombre_grupo'] ?? '');
            if (!$nombreGrupo) {
                $errors[] = [
                    'fila' => $rowNumber,
                    'mensajes' => ['El nombre del grupo es obligatorio.'],
                ];
                continue;
            }

            // Resolver asesor por ID o nombre
            $idAsesor = !empty($row['id_asesor']) ? (int) $row['id_asesor'] : null;
            if (!$idAsesor && !empty($row['nombre_asesor'])) {
                $busqueda = trim($row['nombre_asesor']);
                $asesor = \App\Models\Asesor::where('nombre_asesor', 'like', "%{$busqueda}%")->first();
                if ($asesor) {
                    $idAsesor = $asesor->id;
                }
            }

            $esPref = false;
            if (isset($row['es_socio_preferencial'])) {
                $val = strtolower(trim((string) $row['es_socio_preferencial']));
                $esPref = in_array($val, ['1', 'si', 'sí', 'true', 'yes'], true);
            }

            $grupo = Grupo::where('nombre_grupo', $nombreGrupo)->first();
            if ($grupo) {
                $grupo->update([
                    'id_asesor' => $idAsesor ?? $grupo->id_asesor,
                    'es_socio_preferencial' => $esPref,
                ]);
                $updated++;
            } else {
                $grupo = Grupo::create([
                    'nombre_grupo' => $nombreGrupo,
                    'id_asesor' => $idAsesor,
                    'es_socio_preferencial' => $esPref,
                ]);
                $created++;
            }

            // Si se indicaron integrantes (separados por coma: CURP, ID o Nombre)
            if (!empty($row['integrantes'])) {
                $parts = array_filter(array_map('trim', explode(',', $row['integrantes'])));
                $clientIds = [];
                foreach ($parts as $part) {
                    $c = \App\Models\Cliente::where('id_cliente', $part)
                        ->orWhere('curp', strtoupper($part))
                        ->orWhere('nombre_completo', 'like', "%{$part}%")
                        ->first();
                    if ($c) {
                        $clientIds[] = $c->id_cliente;
                    }
                }
                if (!empty($clientIds)) {
                    $grupo->clientes()->syncWithoutDetaching($clientIds);
                }
            }
        }

        $processed = $created + $updated;
        $parts = [];
        if ($created > 0) $parts[] = "{$created} grupo(s) creado(s)";
        if ($updated > 0) $parts[] = "{$updated} grupo(s) actualizado(s)";

        return response()->json([
            'message' => $processed > 0
                ? 'Importación completada: ' . implode(', ', $parts) . '.'
                : 'No se importó ningún grupo.',
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ], $processed > 0 ? 200 : 422);
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
