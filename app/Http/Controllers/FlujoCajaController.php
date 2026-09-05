<?php

namespace App\Http\Controllers;

use App\Models\MovimientoCaja;
use App\Services\FlujoCajaImportService;
use App\Services\FlujoCajaService;
use Illuminate\Http\Request;

class FlujoCajaController extends Controller
{
    public function __construct(private FlujoCajaService $service) {}

    public function index(Request $request)
    {
        $mes = $request->query('mes') ? (int) $request->query('mes') : null;
        $anio = $request->query('anio') ? (int) $request->query('anio') : null;
        $tipo = $request->query('tipo');

        $query = $this->service->listar($mes, $anio, $tipo);

        return response()->json($query->paginate($request->query('per_page', 20)));
    }

    public function resumen(Request $request)
    {
        $mes = $request->query('mes') ? (int) $request->query('mes') : null;
        $anio = $request->query('anio') ? (int) $request->query('anio') : null;

        return response()->json($this->service->resumen($mes, $anio));
    }

    public function store(Request $request)
    {
        $data = $this->validatedMovimiento($request);

        $mov = $this->service->registrar($data);

        return response()->json([
            'message' => 'Movimiento registrado',
            'data' => $mov,
        ], 201);
    }

    public function update(Request $request, MovimientoCaja $movimiento)
    {
        $data = $this->validatedMovimiento($request);

        try {
            $mov = $this->service->actualizar($movimiento, $data);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Movimiento actualizado',
            'data' => $mov,
        ]);
    }

    public function cuentas()
    {
        return response()->json(['cuentas' => FlujoCajaService::CUENTAS]);
    }

    public function import(Request $request, FlujoCajaImportService $service)
    {
        $data = $request->validate([
            'anio' => 'required|integer|min:2000|max:2100',
            'mes' => 'required|integer|min:1|max:12',
            'rows' => 'required|array|min:1',
            'rows.*.fecha' => 'required|date',
            'rows.*.vendedor' => 'nullable|string',
            'rows.*.motivo' => 'required|string|max:500',
            'rows.*.desembolso' => 'nullable|numeric',
            'rows.*.ingreso' => 'nullable|numeric',
            'rows.*.saldo_excel' => 'nullable|numeric',
            'rows.*.sheet_name' => 'required|string|max:100',
            'rows.*.row_number' => 'required|integer|min:1',
            'reemplazar' => 'boolean',
        ]);

        $result = $service->importar(
            (int) $data['anio'],
            (int) $data['mes'],
            $data['rows'],
            $data['reemplazar'] ?? true
        );

        return response()->json([
            'message' => "Importación completada: {$result['created']} movimiento(s) creados.",
            ...$result,
        ], empty($result['errors']) ? 200 : 207);
    }

    private function validatedMovimiento(Request $request): array
    {
        return $request->validate([
            'fecha' => 'required|date',
            'id_asesor' => 'nullable|integer|exists:asesores,id',
            'motivo' => 'required|string|max:500',
            'tipo' => 'required|in:Ingreso,Egreso',
            'monto' => 'required|numeric|min:0.01',
            'categoria' => 'nullable|string|max:50',
            'cuenta' => 'nullable|string|max:30',
            'num_prog' => 'nullable|integer|exists:creditos,num_prog',
        ]);
    }
}
