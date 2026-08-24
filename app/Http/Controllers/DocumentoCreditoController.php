<?php

namespace App\Http\Controllers;

use App\Models\Credito;
use App\Models\DocumentoCredito;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentoCreditoController extends Controller
{
    public function index($numProg)
    {
        $credito = Credito::findOrFail($numProg);
        return response()->json($credito->documentos()->with('usuario:id,nombre')->get());
    }

    public function store(Request $request, $numProg)
    {
        $credito = Credito::findOrFail($numProg);

        $request->validate([
            'tipo' => 'required|in:PagareFirmado,CartaAdeudoFirmada,TarjetaCobroFirmada,ContratoFirmado,ComprobanteDevolucion,Otro',
            'archivo' => 'required|file|max:15360',
        ]);

        $file = $request->file('archivo');
        $path = $file->store("documentos_credito/{$numProg}", 'public');

        $doc = DocumentoCredito::create([
            'num_prog' => $numProg,
            'tipo' => $request->tipo,
            'nombre_archivo' => $file->getClientOriginalName(),
            'ruta' => $path,
            'subido_por' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Documento de crédito guardado exitosamente',
            'data' => $doc->load('usuario:id,nombre'),
        ], 201);
    }

    public function destroy($numProg, $id)
    {
        $doc = DocumentoCredito::where('num_prog', $numProg)->findOrFail($id);
        Storage::disk('public')->delete($doc->ruta);
        $doc->delete();

        return response()->json(['message' => 'Documento eliminado exitosamente']);
    }

    public function updateExpedienteFisico(Request $request, $numProg)
    {
        $credito = Credito::findOrFail($numProg);

        $validated = $request->validate([
            'ubicacion_expediente' => 'nullable|string|max:255',
            'notas_expediente' => 'nullable|string',
        ]);

        $credito->update($validated);

        return response()->json([
            'message' => 'Ubicación física del expediente actualizada',
            'data' => [
                'ubicacion_expediente' => $credito->ubicacion_expediente,
                'notas_expediente' => $credito->notas_expediente,
            ],
        ]);
    }
}
