<?php

namespace App\Http\Controllers;

use App\Services\AhorroSocioService;
use Illuminate\Http\Request;

class AhorroSocioController extends Controller
{
    public function index(AhorroSocioService $service)
    {
        return response()->json($service->listar());
    }

    public function resumen(Request $request, AhorroSocioService $service)
    {
        $anio = (int) ($request->query('anio') ?? now()->year);
        return response()->json($service->resumenAnual($anio));
    }

    public function ingreso(Request $request, $socioId, AhorroSocioService $service)
    {
        $data = $request->validate([
            'monto' => 'required|numeric|min:0.01',
            'fecha' => 'required|date',
            'notas' => 'nullable|string',
        ]);

        try {
            $ahorro = $service->registrarMovimiento((int) $socioId, $data, 'Ingreso');
            return response()->json(['message' => 'Ingreso registrado', 'data' => $ahorro]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function retiro(Request $request, $socioId, AhorroSocioService $service)
    {
        $data = $request->validate([
            'monto' => 'required|numeric|min:0.01',
            'fecha' => 'required|date',
            'notas' => 'nullable|string',
        ]);

        try {
            $ahorro = $service->registrarMovimiento((int) $socioId, $data, 'Retiro');
            return response()->json(['message' => 'Retiro registrado', 'data' => $ahorro]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
