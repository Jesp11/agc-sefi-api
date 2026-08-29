<?php

namespace App\Http\Controllers;

use App\Models\CatalogoGasto;
use App\Models\GastoOperativo;
use App\Services\CapitalService;
use Illuminate\Http\Request;

class GastoController extends Controller
{
    public function index()
    {
        return response()->json(GastoOperativo::orderByDesc('fecha')->paginate(15));
    }

    public function store(Request $request, CapitalService $capitalService)
    {
        $data = $request->validate([
            'catalogo_gasto_id' => 'nullable|integer|exists:catalogo_gastos,id',
            'concepto' => 'nullable|string|max:255',
            'monto' => 'required|numeric|min:0.01',
            'fecha' => 'required|date',
            'categoria' => 'nullable|string',
            'cuenta' => 'nullable|string|max:30',
        ]);

        if (!empty($data['catalogo_gasto_id'])) {
            $catalogo = CatalogoGasto::where('activo', true)->findOrFail($data['catalogo_gasto_id']);
            $data['concepto'] = $catalogo->concepto;
            $data['categoria'] = $catalogo->categoria;
        }

        if (empty($data['concepto'])) {
            return response()->json([
                'message' => 'Selecciona un gasto del catálogo o indica un concepto.',
                'errors' => ['concepto' => ['El concepto es obligatorio.']],
            ], 422);
        }

        $gasto = $capitalService->registrarGasto($data);
        return response()->json(['message' => 'Gasto registrado', 'data' => $gasto], 201);
    }
}
