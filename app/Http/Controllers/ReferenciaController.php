<?php

namespace App\Http\Controllers;

use App\Models\Referencia;
use App\Http\Requests\StoreReferenciaRequest;
use App\Http\Requests\UpdateReferenciaRequest;
use Illuminate\Http\Request;

class ReferenciaController extends Controller
{
    public function index()
    {
        $referencias = Referencia::with('cliente')->paginate(10);
        return response()->json($referencias);
    }

    public function store(StoreReferenciaRequest $request)
    {
        $referencia = Referencia::create($request->validated());
        return response()->json([
            'message' => 'Referencia creada exitosamente',
            'data' => $referencia
        ], 201);
    }

    public function show($id)
    {
        $referencia = Referencia::with('cliente')->findOrFail($id);
        return response()->json($referencia);
    }

    public function update(UpdateReferenciaRequest $request, $id)
    {
        $referencia = Referencia::findOrFail($id);
        $referencia->update($request->validated());
        return response()->json([
            'message' => 'Referencia actualizada exitosamente',
            'data' => $referencia
        ]);
    }

    public function destroy($id)
    {
        $referencia = Referencia::findOrFail($id);
        $referencia->delete();
        return response()->json([
            'message' => 'Referencia eliminada exitosamente'
        ]);
    }
}
