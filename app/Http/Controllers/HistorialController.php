<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Grupo;
use App\Services\ClienteService;
use App\Services\MoraCalculationService;
use Illuminate\Http\Request;

class HistorialController extends Controller
{
    public function __construct(
        private ClienteService $clienteService,
        private MoraCalculationService $moraService
    ) {}

    public function cliente($id)
    {
        $cliente = Cliente::findOrFail($id);
        return response()->json($this->clienteService->historial($cliente));
    }

    public function grupo($id)
    {
        $grupo = Grupo::with(['clientes', 'creditos.pagos', 'creditos.asesor'])->findOrFail($id);

        $creditos = $grupo->creditos->map(function ($credito) {
            return array_merge($credito->toArray(), [
                'mora' => $this->moraService->calculate($credito),
            ]);
        });

        return response()->json([
            'grupo' => $grupo,
            'creditos' => $creditos,
        ]);
    }

    public function reactivar($id)
    {
        $cliente = Cliente::findOrFail($id);
        $cliente = $this->clienteService->reactivar($cliente);

        return response()->json([
            'message' => 'Cliente reactivado exitosamente',
            'data' => $cliente,
        ]);
    }
}
