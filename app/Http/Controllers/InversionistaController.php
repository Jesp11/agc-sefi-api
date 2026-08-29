<?php

namespace App\Http\Controllers;

use App\Models\Inversionista;
use App\Services\CapitalService;
use Illuminate\Http\Request;

class InversionistaController extends Controller
{
    public function index()
    {
        return response()->json(Inversionista::with('aportaciones')->paginate(15));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'tipo_entidad' => 'nullable|string|max:100',
            'origen_fondeo' => 'nullable|string|max:255',
            'contacto' => 'nullable|string',
            'telefono' => 'nullable|string',
            'email' => 'nullable|email',
            'tasa_preferencial' => 'boolean',
        ]);

        $inv = Inversionista::create($data);
        return response()->json(['message' => 'Inversionista creado', 'data' => $inv], 201);
    }

    public function update(Request $request, $id)
    {
        $inv = Inversionista::findOrFail($id);
        $inv->update($request->validate([
            'nombre' => 'sometimes|string|max:255',
            'tipo_entidad' => 'nullable|string|max:100',
            'origen_fondeo' => 'nullable|string|max:255',
            'contacto' => 'nullable|string',
            'telefono' => 'nullable|string',
            'email' => 'nullable|email',
            'tasa_preferencial' => 'boolean',
            'activo' => 'boolean',
        ]));
        return response()->json(['message' => 'Actualizado', 'data' => $inv]);
    }

    public function aportacion(Request $request, $id, CapitalService $capitalService)
    {
        $data = $request->validate([
            'monto' => 'required|numeric|min:0.01',
            'fecha' => 'required|date',
            'tipo' => 'in:Aportacion,Retiro',
            'notas' => 'nullable|string',
        ]);

        $aportacion = $capitalService->registrarAportacion($id, $data);
        return response()->json(['message' => 'Aportación registrada', 'data' => $aportacion], 201);
    }
}
