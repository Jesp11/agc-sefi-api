<?php

namespace App\Http\Controllers;

use App\Models\Credito;
use App\Http\Requests\RefinanciarCreditoRequest;
use App\Services\RefinanciamientoService;
use InvalidArgumentException;

class RefinanciamientoController extends Controller
{
    public function __construct(
        private RefinanciamientoService $refinanciamientoService
    ) {}

    public function store(RefinanciarCreditoRequest $request, $numProg)
    {
        $credito = Credito::findOrFail($numProg);

        try {
            $nuevo = $this->refinanciamientoService->refinanciar($credito, $request->validated());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Refinanciamiento realizado exitosamente',
            'data' => $nuevo,
        ], 201);
    }
}
