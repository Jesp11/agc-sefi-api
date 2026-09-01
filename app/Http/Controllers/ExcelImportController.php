<?php

namespace App\Http\Controllers;

use App\Services\CarteraMoraImportService;
use App\Services\ExcelImportService;
use Illuminate\Http\Request;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ExcelImportController extends Controller
{
    public function importarCarteraIndividual(Request $request, ExcelImportService $service)
    {
        return $this->importar($request, fn () => $service->importarCarteraIndividual($request->file('archivo')));
    }

    public function importarCarteraGrupal(Request $request, ExcelImportService $service)
    {
        return $this->importar($request, fn () => $service->importarCarteraGrupal($request->file('archivo')));
    }

    public function importarCarteraMora(Request $request, CarteraMoraImportService $service)
    {
        $data = $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.tipo_credito' => 'required|string|in:Individual,Grupal',
            'rows.*.sheet_name' => 'required|string|max:100',
            'rows.*.row_number' => 'required|integer|min:1',
            'rows.*.clasificacion_mora' => 'required|string|in:mora_activa,mora_muerta',
            'rows.*.fecha' => 'required|date',
            'rows.*.cliente' => 'nullable|string|max:255',
            'rows.*.id_cliente' => 'nullable|string|max:50',
            'rows.*.grupo' => 'nullable|string|max:255',
            'rows.*.ciclo' => 'nullable|integer|min:0',
            'rows.*.dias_pago' => 'nullable|string|max:30',
            'rows.*.asesor' => 'nullable|string|max:255',
            'rows.*.valor_ficha' => 'nullable|numeric',
            'rows.*.plazos' => 'nullable|integer|min:1',
            'rows.*.monto_otorgado' => 'nullable|numeric',
            'rows.*.interes' => 'nullable|numeric',
            'rows.*.total' => 'nullable|numeric',
            'rows.*.saldo_total' => 'nullable|numeric',
            'rows.*.saldo_inversion' => 'nullable|numeric',
            'rows.*.curp' => 'nullable|string|max:50',
            'rows.*.clave_elector' => 'nullable|string|max:50',
            'rows.*.telefono' => 'nullable|string|max:50',
            'rows.*.direccion' => 'nullable|string|max:255',
            'rows.*.entre_calles' => 'nullable|string|max:255',
            'rows.*.ocupacion' => 'nullable|string|max:100',
            'rows.*.direccion_trabajo' => 'nullable|string|max:255',
            'rows.*.telefono_trabajo' => 'nullable|string|max:50',
        ]);

        $result = $service->importar($data['rows']);

        return response()->json([
            'message' => "Importación completada: {$result['creditos_created']} crédito(s) creados, {$result['creditos_updated']} actualizado(s).",
            ...$result,
        ], empty($result['errors']) ? 200 : 207);
    }

    public function importarInversionistas(Request $request, ExcelImportService $service)
    {
        return $this->importar($request, fn () => $service->importarInversionistas($request->file('archivo')));
    }

    public function importarFlujoCaja(Request $request, ExcelImportService $service)
    {
        return $this->importar($request, fn () => $service->importarFlujoCaja($request->file('archivo')));
    }

    private function importar(Request $request, callable $callback)
    {
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls',
        ]);

        try {
            return response()->json($callback());
        } catch (ProcessFailedException $e) {
            $process = $e->getProcess();

            return response()->json([
                'message' => 'La importación falló.',
                'output' => $this->splitOutput($process->getOutput()),
                'error' => $this->splitOutput($process->getErrorOutput()),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function splitOutput(string $output): array
    {
        return array_values(array_filter(array_map('trim', preg_split("/\r\n|\n|\r/", $output) ?: [])));
    }
}
