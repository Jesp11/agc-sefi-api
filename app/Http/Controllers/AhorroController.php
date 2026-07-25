<?php

namespace App\Http\Controllers;

use App\Models\AhorroEmpleado;
use App\Models\AhorroMovimiento;
use App\Services\CapitalService;
use Illuminate\Http\Request;

class AhorroController extends Controller
{
    public function index(CapitalService $capitalService)
    {
        return response()->json($capitalService->totalAhorros());
    }

    public function retiro(Request $request, $empleadoId)
    {
        $data = $request->validate([
            'monto' => 'required|numeric|min:0.01',
            'fecha' => 'required|date',
            'notas' => 'nullable|string',
        ]);

        $ahorro = AhorroEmpleado::where('empleado_id', $empleadoId)->firstOrFail();

        if ($ahorro->saldo < $data['monto']) {
            return response()->json(['message' => 'Saldo insuficiente'], 422);
        }

        $ahorro->decrement('saldo', $data['monto']);
        AhorroMovimiento::create([
            'ahorro_id' => $ahorro->id,
            'tipo' => 'Retiro',
            'monto' => $data['monto'],
            'fecha' => $data['fecha'],
            'notas' => $data['notas'] ?? null,
        ]);

        return response()->json(['message' => 'Retiro registrado', 'data' => $ahorro->fresh()]);
    }
}
