<?php

namespace App\Http\Controllers;

use App\Models\Credito;
use App\Models\Cliente;
use App\Http\Requests\StoreCreditoRequest;
use App\Http\Requests\UpdateCreditoRequest;
use Illuminate\Http\Request;

class CreditoController extends Controller
{
    public function index()
    {
        $creditos = Credito::with(['cliente', 'asesor'])->paginate(10);
        return response()->json($creditos);
    }

    public function store(StoreCreditoRequest $request)
    {
        $data = $request->validated();
        
        $cliente = Cliente::findOrFail($data['id_cliente']);
        $data['id_asesor'] = $cliente->id_asesor;
        $data['ciclo'] = 0;

        $credito = Credito::create($data);
        return response()->json([
            'message' => 'Crédito creado exitosamente',
            'data' => $credito
        ], 201);
    }

    public function show($id)
    {
        $credito = Credito::with(['cliente', 'asesor'])->findOrFail($id);
        return response()->json($credito);
    }

    public function update(UpdateCreditoRequest $request, $id)
    {
        $credito = Credito::findOrFail($id);
        $credito->update($request->validated());
        return response()->json([
            'message' => 'Crédito actualizado exitosamente',
            'data' => $credito
        ]);
    }

    public function destroy($id)
    {
        $credito = Credito::findOrFail($id);
        $credito->delete();
        return response()->json([
            'message' => 'Crédito eliminado exitosamente'
        ]);
    }
}
