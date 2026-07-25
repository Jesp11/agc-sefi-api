<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\DocumentoCliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentoClienteController extends Controller
{
    public function index($idCliente)
    {
        $cliente = Cliente::findOrFail($idCliente);
        return response()->json($cliente->documentos);
    }

    public function store(Request $request, $idCliente)
    {
        $cliente = Cliente::findOrFail($idCliente);

        $request->validate([
            'tipo' => 'required|in:INE,INEReverso,ComprobanteDomicilio,Foto,SolicitudPrestamo,Otro',
            'archivo' => 'required|file|max:10240',
        ]);

        $file = $request->file('archivo');
        $path = $file->store("documentos/{$idCliente}", 'public');

        $doc = DocumentoCliente::create([
            'id_cliente' => $idCliente,
            'tipo' => $request->tipo,
            'nombre_archivo' => $file->getClientOriginalName(),
            'ruta' => $path,
            'subido_por' => auth()->id(),
        ]);

        return response()->json(['message' => 'Documento subido', 'data' => $doc], 201);
    }

    public function destroy($idCliente, $id)
    {
        $doc = DocumentoCliente::where('id_cliente', $idCliente)->findOrFail($id);
        Storage::disk('public')->delete($doc->ruta);
        $doc->delete();

        return response()->json(['message' => 'Documento eliminado']);
    }
}
