<?php

namespace App\Http\Controllers;

use App\Models\Asesor;
use App\Http\Requests\StoreAsesorRequest;
use App\Http\Requests\UpdateAsesorRequest;
use Illuminate\Http\Request;

class AsesorController extends Controller
{
    public function index()
    {
        $asesores = Asesor::paginate(10);
        return response()->json($asesores);
    }

    public function store(StoreAsesorRequest $request)
    {
        $asesor = Asesor::create($request->validated());
        return response()->json([
            'message' => 'Asesor creado exitosamente',
            'data' => $asesor
        ], 201);
    }

    public function show($id)
    {
        $asesor = Asesor::with('creditos')->findOrFail($id);
        return response()->json($asesor);
    }

    public function update(UpdateAsesorRequest $request, $id)
    {
        $asesor = Asesor::findOrFail($id);
        $asesor->update($request->validated());
        return response()->json([
            'message' => 'Asesor actualizado exitosamente',
            'data' => $asesor
        ]);
    }

    public function destroy($id)
    {
        $asesor = Asesor::findOrFail($id);
        $asesor->delete();
        return response()->json([
            'message' => 'Asesor eliminado exitosamente'
        ]);
    }
}
