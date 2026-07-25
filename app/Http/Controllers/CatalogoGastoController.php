<?php

namespace App\Http\Controllers;

use App\Models\CatalogoGasto;
use Illuminate\Http\Request;

class CatalogoGastoController extends Controller
{
    public function index(Request $request)
    {
        $query = CatalogoGasto::query()->orderBy('concepto');

        if ($request->boolean('solo_activos')) {
            $query->where('activo', true);
        }

        return response()->json($query->paginate($request->query('per_page', 50)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'concepto' => 'required|string|max:255',
            'categoria' => 'nullable|string|max:255',
            'monto_sugerido' => 'nullable|numeric|min:0.01',
        ]);

        $item = CatalogoGasto::create($data);

        return response()->json(['message' => 'Gasto de catálogo creado', 'data' => $item], 201);
    }

    public function update(Request $request, $id)
    {
        $item = CatalogoGasto::findOrFail($id);
        $item->update($request->validate([
            'concepto' => 'sometimes|string|max:255',
            'categoria' => 'nullable|string|max:255',
            'monto_sugerido' => 'nullable|numeric|min:0.01',
            'activo' => 'boolean',
        ]));

        return response()->json(['message' => 'Actualizado', 'data' => $item]);
    }
}
