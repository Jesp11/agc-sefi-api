<?php

namespace App\Http\Controllers;

use App\Models\CatalogoGasto;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CatalogoGastoController extends Controller
{
    public function index(Request $request)
    {
        $query = CatalogoGasto::query()->orderBy('categoria');

        if ($request->boolean('solo_activos')) {
            $query->where('activo', true);
        }

        return response()->json($query->paginate($request->query('per_page', 50)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'categoria' => 'required|string|max:255',
        ]);

        $data['concepto'] = null;
        $item = CatalogoGasto::create($data);

        return response()->json(['message' => 'Categoría creada', 'data' => $item], 201);
    }

    public function update(Request $request, $id)
    {
        $item = CatalogoGasto::findOrFail($id);
        $data = $request->validate([
            'categoria' => 'sometimes|required|string|max:255',
            'activo' => 'boolean',
        ]);

        $categoria = $data['categoria'] ?? $item->categoria;
        $activo = $data['activo'] ?? $item->activo;
        if ($activo && ! filled($categoria)) {
            throw ValidationException::withMessages([
                'categoria' => ['Una categoría activa debe tener un nombre.'],
            ]);
        }

        $item->update($data);

        return response()->json(['message' => 'Actualizado', 'data' => $item]);
    }
}
