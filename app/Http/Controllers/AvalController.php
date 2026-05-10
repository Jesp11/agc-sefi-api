<?php

namespace App\Http\Controllers;

use App\Models\Aval;
use App\Http\Requests\StoreAvalRequest;
use App\Http\Requests\UpdateAvalRequest;
use Illuminate\Http\Request;

class AvalController extends Controller
{
    public function index()
    {
        $avales = Aval::with('cliente')->paginate(10);
        return response()->json($avales);
    }

    public function store(StoreAvalRequest $request)
    {
        $aval = Aval::create($request->validated());
        return response()->json([
            'message' => 'Aval creado exitosamente',
            'data' => $aval
        ], 201);
    }

    public function show($id)
    {
        $aval = Aval::with('cliente')->findOrFail($id);
        return response()->json($aval);
    }

    public function update(UpdateAvalRequest $request, $id)
    {
        $aval = Aval::findOrFail($id);
        $aval->update($request->validated());
        return response()->json([
            'message' => 'Aval actualizado exitosamente',
            'data' => $aval
        ]);
    }

    public function destroy($id)
    {
        $aval = Aval::findOrFail($id);
        $aval->delete();
        return response()->json([
            'message' => 'Aval eliminado exitosamente'
        ]);
    }
}
