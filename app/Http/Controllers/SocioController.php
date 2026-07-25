<?php

namespace App\Http\Controllers;

use App\Models\AhorroSocio;
use App\Models\Socio;
use Illuminate\Http\Request;

class SocioController extends Controller
{
    public function index()
    {
        return response()->json(Socio::with('ahorro')->orderBy('nombre')->paginate(50));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'codigo' => 'required|string|max:50|unique:socios,codigo',
        ]);

        $socio = Socio::create($data);
        AhorroSocio::create(['socio_id' => $socio->id, 'saldo' => 0]);

        return response()->json(['message' => 'Socio registrado', 'data' => $socio->load('ahorro')], 201);
    }

    public function update(Request $request, $id)
    {
        $socio = Socio::findOrFail($id);
        $socio->update($request->validate([
            'nombre' => 'sometimes|string|max:255',
            'codigo' => 'sometimes|string|max:50|unique:socios,codigo,' . $id,
            'activo' => 'boolean',
        ]));

        return response()->json(['message' => 'Actualizado', 'data' => $socio]);
    }
}
