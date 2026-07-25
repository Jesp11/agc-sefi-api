<?php

namespace App\Http\Controllers;

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
        $data = $request->validate([
            'fecha' => 'required|date',
            'id_asesor' => 'nullable|integer|exists:asesores,id',
            'motivo' => 'required|string|max:500',
            'tipo' => 'required|in:Ingreso,Egreso',
            'monto' => 'required|numeric|min:0.01',
            'categoria' => 'nullable|string|max:50',
            'cuenta' => 'nullable|string|max:30',
            'num_prog' => 'nullable|integer|exists:creditos,num_prog',
        ]);

        $mov = $this->service->registrar($data);

        return response()->json([
            'message' => 'Movimiento registrado',
            'data' => $mov,
        ], 201);
    }

    public function cuentas()
    {
        return response()->json(['cuentas' => FlujoCajaService::CUENTAS]);
    }
}
