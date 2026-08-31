<?php

namespace App\Http\Controllers;

use App\Services\AhorroPersonalService;
use Illuminate\Http\Request;

class AhorroPersonalController extends Controller
{
    public function index(AhorroPersonalService $service)
    {
        return response()->json($service->listar());
    }

    public function resumen(Request $request, AhorroPersonalService $service)
    {
        $anio = (int) ($request->query('anio') ?? now()->year);
        return response()->json($service->resumenAnual($anio));
    }

    public function ingreso(Request $request, $asesorId, AhorroPersonalService $service)
    {
        $data = $request->validate([
            'monto' => 'required|numeric|min:0.01',
            'fecha' => 'required|date',
            'notas' => 'nullable|string',
        ]);

        try {
            $ahorro = $service->registrarMovimiento((int) $asesorId, $data, 'Ingreso');
            return response()->json(['message' => 'Ingreso registrado', 'data' => $ahorro]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function retiro(Request $request, $asesorId, AhorroPersonalService $service)
    {
        $data = $request->validate([
            'monto' => 'required|numeric|min:0.01',
            'fecha' => 'required|date',
            'notas' => 'nullable|string',
        ]);

        try {
            $ahorro = $service->registrarMovimiento((int) $asesorId, $data, 'Retiro');
            return response()->json(['message' => 'Retiro registrado', 'data' => $ahorro]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function import(Request $request, AhorroPersonalService $service)
    {
        $data = $request->validate([
            'anio' => 'required|integer|min:2000|max:2100',
            'filas' => 'required|array|min:1',
            'filas.*.codigo' => 'nullable|string',
            'filas.*.nombre' => 'nullable|string',
            'filas.*.meses' => 'required|array',
            'reemplazar' => 'boolean',
        ]);

        $result = $service->importarAnual(
            (int) $data['anio'],
            $data['filas'],
            $data['reemplazar'] ?? true
        );

        return response()->json($result, empty($result['errores']) ? 200 : 207);
    }
}
