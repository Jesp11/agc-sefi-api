<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Credito;
use App\Http\Requests\StoreClienteRequest;
use App\Http\Requests\UpdateClienteRequest;
use App\Services\ClienteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ClienteController extends Controller
{
    public function __construct(private ClienteService $clienteService) {}

    public function index(Request $request)
    {
        $clientes = Cliente::with(['creditos.asesor', 'referencias', 'avales', 'asesor', 'grupos'])
            ->paginate($request->query('per_page', 10));
        return response()->json($clientes);
    }

    public function export()
    {
        $clientes = Cliente::with(['asesor', 'grupos'])
            ->orderBy('nombre_completo')
            ->get();

        $data = $clientes->map(function (Cliente $cliente) {
            return [
                'id_cliente' => $cliente->id_cliente,
                'nombre_completo' => $cliente->nombre_completo,
                'curp' => $cliente->curp,
                'clave_elector' => $cliente->clave_elector,
                'telefono' => $cliente->telefono,
                'direccion' => $cliente->direccion,
                'entre_calles' => $cliente->entre_calles,
                'ocupacion' => $cliente->ocupacion,
                'direccion_trabajo' => $cliente->direccion_trabajo,
                'telefono_trabajo' => $cliente->telefono_trabajo,
                'nombre_asesor' => $cliente->asesor?->nombre_asesor,
                'nombre_grupo' => $cliente->grupos->first()?->nombre_grupo,
                'estatus' => $cliente->estatus,
                'created_at' => $cliente->created_at,
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function import(Request $request)
    {
        $request->validate([
            'clientes' => 'required|array|min:1',
            'clientes.*.nombre_completo' => 'required|string|max:255',
            'clientes.*.curp' => 'required|string|size:18',
            'clientes.*.clave_elector' => 'nullable|string|max:255',
            'clientes.*.telefono' => 'nullable|string|max:20',
            'clientes.*.direccion' => 'nullable|string',
            'clientes.*.entre_calles' => 'nullable|string|max:255',
            'clientes.*.ocupacion' => 'nullable|string|max:255',
            'clientes.*.direccion_trabajo' => 'nullable|string',
            'clientes.*.telefono_trabajo' => 'nullable|string|max:20',
            'clientes.*.id_asesor' => 'nullable|integer|exists:asesores,id',
            'clientes.*.nombre_asesor' => 'nullable|string|max:255',
            'clientes.*.id_grupo' => 'nullable|integer|exists:grupos,id',
            'clientes.*.nombre_grupo' => 'nullable|string|max:255',
        ]);

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($request->input('clientes') as $index => $row) {
            $rowNumber = $index + 2;
            $validator = Validator::make($row, [
                'nombre_completo' => 'required|string|max:255',
                'curp' => 'required|string|size:18',
                'clave_elector' => 'nullable|string|max:255',
                'telefono' => 'nullable|string|max:20',
                'direccion' => 'nullable|string',
                'entre_calles' => 'nullable|string|max:255',
                'ocupacion' => 'nullable|string|max:255',
                'direccion_trabajo' => 'nullable|string',
                'telefono_trabajo' => 'nullable|string|max:20',
                'id_asesor' => 'nullable|integer|exists:asesores,id',
                'nombre_asesor' => 'nullable|string|max:255',
                'id_grupo' => 'nullable|integer|exists:grupos,id',
                'nombre_grupo' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                $errors[] = [
                    'fila' => $rowNumber,
                    'mensajes' => $validator->errors()->all(),
                ];
                continue;
            }

            $data = $validator->validated();
            $data['curp'] = strtoupper($data['curp']);
            $data['id_asesor'] = $this->clienteService->resolveAsesorId($data);
            $data['id_grupo'] = $this->clienteService->resolveGrupoId($data);

            if (! $data['id_asesor']) {
                $errors[] = [
                    'fila' => $rowNumber,
                    'mensajes' => ['No se encontró el asesor indicado.'],
                ];
                continue;
            }

            $result = $this->clienteService->upsertFromImport($data);
            if ($result['action'] === 'created') {
                $created++;
            } else {
                $updated++;
            }
        }

        $processed = $created + $updated;
        $parts = [];
        if ($created > 0) {
            $parts[] = "{$created} creado(s)";
        }
        if ($updated > 0) {
            $parts[] = "{$updated} actualizado(s)";
        }

        return response()->json([
            'message' => $processed > 0
                ? 'Importación completada: ' . implode(', ', $parts) . '.'
                : 'No se importó ningún cliente.',
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ], $processed > 0 ? 200 : 422);
    }

    public function store(StoreClienteRequest $request)
    {
        $data = $request->validated();
        
        if (isset($data['id_grupo'])) {
            $grupo = \App\Models\Grupo::findOrFail($data['id_grupo']);
            $data['id_asesor'] = $grupo->id_asesor;
        }

        $cliente = $this->clienteService->create($data);

        return response()->json([
            'message' => 'Cliente creado exitosamente',
            'data' => $cliente
        ], 201);
    }

    public function show($id)
    {
        $cliente = Cliente::with(['creditos.asesor', 'referencias', 'avales', 'grupos', 'asesor'])->findOrFail($id);
        return response()->json($cliente);
    }

    public function update(UpdateClienteRequest $request, $id)
    {
        $cliente = Cliente::findOrFail($id);
        $data = $request->validated();
        $asesorAnterior = $cliente->id_asesor;

        if (array_key_exists('id_asesor', $data)) {
            $esAdmin = $request->user()?->role?->nombre === 'admin';
            if (!$esAdmin) {
                return response()->json(['message' => 'Solo un administrador puede cambiar el asesor.'], 403);
            }
        }

        $cliente->update($data);

        if (isset($data['id_grupo'])) {
            $cliente->grupos()->sync([$data['id_grupo']]);
        }

        $creditosActualizados = 0;
        if (array_key_exists('id_asesor', $data) && (int) $data['id_asesor'] !== (int) $asesorAnterior) {
            $creditosActualizados = Credito::where('id_cliente', $cliente->id_cliente)
                ->whereIn('estado', ['Activo', 'EnMora'])
                ->update(['id_asesor' => $data['id_asesor']]);
        }

        return response()->json([
            'message' => $creditosActualizados > 0
                ? "Cliente actualizado. {$creditosActualizados} crédito(s) activo(s) reasignado(s) al nuevo asesor."
                : 'Cliente actualizado exitosamente',
            'data' => $cliente->load(['grupos', 'asesor', 'creditos.asesor']),
            'creditos_reasignados' => $creditosActualizados,
        ]);
    }

    public function destroy($id)
    {
        $cliente = Cliente::findOrFail($id);
        $cliente->delete();
        return response()->json([
            'message' => 'Cliente eliminado exitosamente'
        ]);
    }
}
