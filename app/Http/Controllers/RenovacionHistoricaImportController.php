<?php

namespace App\Http\Controllers;

use App\Services\RenovacionHistoricaImportService;
use App\Services\RenovacionHistoricaValidationException;
use Illuminate\Http\Request;

class RenovacionHistoricaImportController extends Controller
{
    public function __construct(private RenovacionHistoricaImportService $service) {}

    public function preview(Request $request)
    {
        $data = $this->validatedPayload($request);

        return response()->json($this->service->previsualizar($data['rows'], $data['columns'] ?? []));
    }

    public function confirm(Request $request)
    {
        $data = $this->validatedPayload($request);

        try {
            $result = $this->service->confirmar($data['rows'], $data['columns'] ?? []);
        } catch (RenovacionHistoricaValidationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                ...$exception->preview,
            ], 422);
        }

        return response()->json([
            'message' => "Importación histórica completada: {$result['total']} renovación(es) enlazada(s).",
            ...$result,
        ]);
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'columns' => 'nullable|array',
            'columns.*' => 'nullable|string|max:100',
            'rows' => 'required|array|min:1|max:2000',
            'rows.*.row_number' => 'nullable|integer|min:1',
            'rows.*.folio_credito_anterior' => 'nullable',
            'rows.*.folio_credito_nuevo' => 'nullable',
            'rows.*.saldo_absorbido' => 'nullable',
            'rows.*.monto_neto' => 'nullable',
            'rows.*.fecha_efectiva' => 'nullable',
            'rows.*.intereses_arrastrados' => 'nullable',
            'rows.*.notas' => 'nullable|string|max:2000',
        ]);
    }
}
