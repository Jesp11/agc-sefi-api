<?php

namespace App\Http\Controllers;

use App\Models\CatalogoGasto;
use App\Models\GastoOperativo;
use App\Services\CapitalService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GastoController extends Controller
{
    public function index()
    {
        return response()->json(GastoOperativo::orderByDesc('fecha')->paginate(15));
    }

    public function store(Request $request, CapitalService $capitalService)
    {
        $data = $request->validate([
            'catalogo_gasto_id' => 'required|integer|exists:catalogo_gastos,id',
            'concepto' => 'required|string|max:255',
            'monto' => 'required|numeric|min:0.01',
            'fecha' => 'required|date',
            'cuenta' => 'nullable|string|max:30',
        ]);

        $catalogo = $this->catalogoParaGasto((int) $data['catalogo_gasto_id']);
        $data['categoria'] = $catalogo->categoria;

        $gasto = $capitalService->registrarGasto($data);

        return response()->json(['message' => 'Gasto registrado', 'data' => $gasto], 201);
    }

    public function update(Request $request, GastoOperativo $gasto, CapitalService $capitalService)
    {
        $data = $request->validate([
            'catalogo_gasto_id' => 'required|integer|exists:catalogo_gastos,id',
            'concepto' => 'required|string|max:255',
            'monto' => 'required|numeric|min:0.01',
            'fecha' => 'required|date',
            'cuenta' => 'nullable|string|max:30',
        ]);

        // Un gasto histórico puede conservar su referencia aunque el concepto
        // haya sido desactivado; no se permite usar un inactivo para otro gasto.
        $catalogo = $this->catalogoParaGasto((int) $data['catalogo_gasto_id'], $gasto);
        $data['categoria'] = $catalogo->categoria;

        $gasto = $capitalService->actualizarGasto($gasto, $data);

        return response()->json(['message' => 'Gasto actualizado', 'data' => $gasto]);
    }

    private function catalogoParaGasto(int $catalogoId, ?GastoOperativo $gasto = null): CatalogoGasto
    {
        $catalogo = CatalogoGasto::findOrFail($catalogoId);

        if (! $catalogo->activo && (! $gasto || (int) $gasto->catalogo_gasto_id !== $catalogo->id)) {
            throw ValidationException::withMessages([
                'catalogo_gasto_id' => ['El gasto seleccionado no está activo.'],
            ]);
        }

        return $catalogo;
    }
}
