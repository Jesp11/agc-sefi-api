<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Http\Requests\StoreClienteRequest;
use App\Http\Requests\UpdateClienteRequest;
use Illuminate\Http\Request;

class ClienteController extends Controller
{
    public function index()
    {
        $clientes = Cliente::with(['creditos', 'referencias', 'avales'])->paginate(10);
        return response()->json($clientes);
    }

    public function store(StoreClienteRequest $request)
    {
        $data = $request->validated();
        
        // Generar id_cliente automático
        $nombre = $data['nombre_completo'];
        $words = explode(' ', strtoupper(trim($nombre)));
        $initials = '';
        foreach ($words as $word) {
            if (!empty($word)) {
                $initials .= substr($word, 0, 1);
            }
        }
        
        $lastCliente = Cliente::where('id_cliente', 'like', $initials . '%')
            ->orderBy('id_cliente', 'desc')
            ->first();
            
        if ($lastCliente) {
            $numberStr = substr($lastCliente->id_cliente, strlen($initials));
            $number = (int) $numberStr;
            $newNumber = $number + 1;
        } else {
            $newNumber = 1;
        }
        
        $data['id_cliente'] = $initials . str_pad($newNumber, 3, '0', STR_PAD_LEFT);

        $cliente = Cliente::create($data);
        return response()->json([
            'message' => 'Cliente creado exitosamente',
            'data' => $cliente
        ], 201);
    }

    public function show($id)
    {
        $cliente = Cliente::with(['creditos', 'referencias', 'avales'])->findOrFail($id);
        return response()->json($cliente);
    }

    public function update(UpdateClienteRequest $request, $id)
    {
        $cliente = Cliente::findOrFail($id);
        $cliente->update($request->validated());
        return response()->json([
            'message' => 'Cliente actualizado exitosamente',
            'data' => $cliente
        ]);
    }

    public function destroy($id)
    {
        $cliente = Cliente::findOrFail($id);
        $cliente->delete();
        return response()->json([
            'message' => 'Cliente eliminado exitosamente'
        ]);
    }
}
