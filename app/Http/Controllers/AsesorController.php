<?php

namespace App\Http\Controllers;

use App\Models\Asesor;
use App\Http\Requests\StoreAsesorRequest;
use App\Http\Requests\UpdateAsesorRequest;
use App\Services\AsesorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AsesorController extends Controller
{
    public function __construct(private AsesorService $asesorService) {}

    public function index(Request $request)
    {
        $asesores = Asesor::with(['user:id,email,id_asesor'])
            ->paginate($request->query('per_page', 10));
        return response()->json($asesores);
    }

    public function export()
    {
        $asesores = Asesor::orderBy('nombre_asesor')->get([
            'id_asesor',
            'nombre_asesor',
            'curp',
            'telefono',
            'cumpleanos',
            'created_at',
        ]);

        return response()->json(['data' => $asesores]);
    }

    public function import(Request $request)
    {
        $request->validate([
            'asesores' => 'required|array|min:1',
            'asesores.*.nombre_asesor' => 'required|string|max:255',
            'asesores.*.curp' => 'required|string|size:18',
            'asesores.*.telefono' => 'nullable|string|max:20',
        ]);

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($request->input('asesores') as $index => $row) {
            $rowNumber = $index + 2;
            $validator = Validator::make($row, [
                'nombre_asesor' => 'required|string|max:255',
                'curp' => 'required|string|size:18',
                'telefono' => 'nullable|string|max:20',
            ]);

            if ($validator->fails()) {
                $errors[] = [
                    'fila' => $rowNumber,
                    'mensajes' => $validator->errors()->all(),
                ];
                continue;
            }

            $data = $validator->validated();
            if (! empty($data['telefono'])) {
                $data['telefono'] = trim($data['telefono']);
            } else {
                unset($data['telefono']);
            }

            $result = $this->asesorService->upsertFromImport($data);
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
                : 'No se importó ningún asesor.',
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ], $processed > 0 ? 200 : 422);
    }

    public function store(StoreAsesorRequest $request)
    {
        $data = $request->validated();

        if ($request->hasFile('ine')) {
            $data['ine_path'] = $request->file('ine')->store('ines', 'public');
        }
        if ($request->hasFile('ine_2')) {
            $data['ine_path_2'] = $request->file('ine_2')->store('ines', 'public');
        }

        unset($data['ine'], $data['ine_2']);

        $asesor = $this->asesorService->create($data);
        return response()->json([
            'message' => 'Asesor creado exitosamente',
            'data' => $asesor
        ], 201);
    }

    public function show($id)
    {
        $asesor = Asesor::with(['creditos', 'user:id,name,email,id_asesor,role_id,created_at'])->findOrFail($id);
        return response()->json($asesor);
    }

    public function verIne($id, $slot)
    {
        $asesor = Asesor::findOrFail($id);
        $path = ((int) $slot === 2) ? $asesor->ine_path_2 : $asesor->ine_path;

        if (! $path || ! Storage::disk('public')->exists($path)) {
            return response()->json(['message' => 'Archivo no encontrado en el servidor'], 404);
        }

        $mimeType = Storage::disk('public')->mimeType($path) ?: 'application/octet-stream';
        $file = Storage::disk('public')->get($path);

        return response($file, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    public function crearAcceso(Request $request, $id)
    {
        $asesor = Asesor::findOrFail($id);

        $data = $request->validate([
            'email' => 'required|email|max:100',
            'password' => 'nullable|string|min:6|max:50',
        ]);

        try {
            $result = $this->asesorService->crearAcceso(
                $asesor,
                $data['email'],
                $data['password'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Acceso creado. Comparte el correo y la contraseña temporal con el asesor.',
            'user' => [
                'id' => $result['user']->id,
                'name' => $result['user']->name,
                'email' => $result['user']->email,
                'role' => $result['user']->role?->nombre,
            ],
            'password_temporal' => $result['password'],
        ], 201);
    }

    public function actualizarAcceso(Request $request, $id)
    {
        $asesor = Asesor::findOrFail($id);

        $data = $request->validate([
            'email' => 'nullable|email|max:100',
            'password' => 'nullable|string|min:6|max:50',
            'regenerar_password' => 'sometimes|boolean',
        ]);

        try {
            $result = $this->asesorService->actualizarAcceso(
                $asesor,
                $data['email'] ?? null,
                $data['password'] ?? null,
                (bool) ($data['regenerar_password'] ?? false)
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $result['password']
                ? 'Acceso actualizado. Comparte la nueva contraseña temporal con el asesor.'
                : 'Acceso actualizado.',
            'user' => [
                'id' => $result['user']->id,
                'name' => $result['user']->name,
                'email' => $result['user']->email,
                'role' => $result['user']->role?->nombre,
            ],
            'password_temporal' => $result['password'],
        ]);
    }

    public function update(UpdateAsesorRequest $request, $id)
    {
        $asesor = Asesor::findOrFail($id);
        $data = $request->validated();

        if ($request->boolean('delete_ine')) {
            if ($asesor->ine_path) Storage::disk('public')->delete($asesor->ine_path);
            $data['ine_path'] = null;
        } elseif ($request->hasFile('ine')) {
            if ($asesor->ine_path) Storage::disk('public')->delete($asesor->ine_path);
            $data['ine_path'] = $request->file('ine')->store('ines', 'public');
        }

        if ($request->boolean('delete_ine_2')) {
            if ($asesor->ine_path_2) Storage::disk('public')->delete($asesor->ine_path_2);
            $data['ine_path_2'] = null;
        } elseif ($request->hasFile('ine_2')) {
            if ($asesor->ine_path_2) Storage::disk('public')->delete($asesor->ine_path_2);
            $data['ine_path_2'] = $request->file('ine_2')->store('ines', 'public');
        }

        unset($data['delete_ine'], $data['delete_ine_2']);
        $asesor->update($data);
        return response()->json([
            'message' => 'Asesor actualizado exitosamente',
            'data' => $asesor
        ]);
    }

    public function destroy($id)
    {
        $asesor = Asesor::findOrFail($id);
        $asesor->delete();
        return response()->json([
            'message' => 'Asesor eliminado exitosamente'
        ]);
    }
}
