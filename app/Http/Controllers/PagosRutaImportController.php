<?php

namespace App\Http\Controllers;

use App\Services\PagosRutaImportService;
use App\Services\PagosRutaImportValidationException;
use Illuminate\Http\Request;

class PagosRutaImportController extends Controller
{
    public function __construct(private PagosRutaImportService $service) {}

    public function preview(Request $request)
    {
        $data = $this->validatedPayload($request);

        return response()->json($this->service->previsualizar($data['fecha'], $data['rows'], $data['columns'] ?? []));
    }

    public function confirm(Request $request)
    {
        $data = $this->validatedPayload($request);
        try {
            $result = $this->service->confirmar($data['fecha'], $data['rows'], $data['columns'] ?? []);
        } catch (PagosRutaImportValidationException $exception) {
            return response()->json(['message' => $exception->getMessage(), ...$exception->preview], 422);
        }

        return response()->json([
            'message' => "Importación completada: {$result['created']} pago(s) creado(s), {$result['omitted']} omitido(s).",
            ...$result,
        ]);
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'fecha' => 'required|date',
            'columns' => 'required|array',
            'columns.*' => 'nullable|string|max:100',
            'rows' => 'required|array|min:1|max:2000',
            'rows.*.row_number' => 'nullable|integer|min:1',
            'rows.*.folio' => 'nullable',
            'rows.*.cliente_grupo' => 'nullable|string|max:255',
            'rows.*.gestor' => 'nullable|string|max:255',
            'rows.*.categoria' => 'nullable|string|max:100',
            'rows.*.cuota' => 'nullable',
            'rows.*.fecha_cuota' => 'nullable',
            'rows.*.importe_esperado' => 'nullable',
            'rows.*.fecha_pago' => 'nullable',
            'rows.*.referencia_ruta' => 'nullable|string|max:100',
            'rows.*.pago_realizado' => 'nullable',
            'rows.*.metodo_pago' => 'nullable|string|max:50',
            'rows.*.notas' => 'nullable|string|max:2000',
        ]);
    }
}
