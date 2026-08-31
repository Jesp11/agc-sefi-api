<?php

namespace App\Http\Controllers;

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

    public function importarCarteraMora(Request $request, ExcelImportService $service)
    {
        return $this->importar($request, fn () => $service->importarCarteraMora($request->file('archivo')));
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
