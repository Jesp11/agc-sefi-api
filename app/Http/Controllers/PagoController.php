<?php

namespace App\Http\Controllers;

use App\Models\Credito;
use App\Http\Requests\StorePagoRequest;
use App\Services\MoraCalculationService;
use App\Services\PagoService;
use Illuminate\Http\Request;

class PagoController extends Controller
{
    public function __construct(
        private PagoService $pagoService,
        private MoraCalculationService $moraService
    ) {}

    public function index($numProg)
    {
        $credito = Credito::findOrFail($numProg);
        return response()->json($this->pagoService->historial($credito));
    }

    public function store(StorePagoRequest $request, $numProg)
    {
        $credito = Credito::with(['cliente', 'grupo', 'asesor'])->findOrFail($numProg);
        $result = $this->pagoService->registrar($credito, $request->validated());
        $credito = $credito->fresh()->load(['cliente', 'grupo', 'asesor', 'pagos']);

        $message = $result['multa']
            ? 'Abono y multa registrados exitosamente'
            : 'Pago registrado exitosamente';

        $abono = (float) $result['pago']->monto;
        $multa = $result['multa'] ? (float) $result['multa']->monto : 0.0;

        $numPago = $credito->pagos->where('tipo', 'Abono')->count();
        $totalPagos = (int) $credito->plazos;

        return response()->json([
            'message' => $message,
            'data' => $result['pago'],
            'pagos' => $result['pagos'],
            'multa' => $result['multa'],
            'mora' => $this->moraService->calculate($credito),
            'ticket' => [
                'num_prog' => $credito->num_prog,
                'tipo_credito' => $credito->tipo_credito,
                'beneficiario' => $credito->tipo_credito === 'Grupal'
                    ? ($credito->grupo?->nombre_grupo ?? 'Grupo')
                    : ($credito->cliente?->nombre_completo ?? 'Cliente'),
                'asesor' => $credito->asesor?->nombre_asesor,
                'fecha' => $result['pago']->fecha?->format('Y-m-d') ?? $result['pago']->fecha,
                'hora' => $result['pago']->hora,
                'metodo_pago' => $result['pago']->metodo_pago,
                'abono' => $abono,
                'multa' => $multa,
                'total' => round($abono + $multa, 2),
                'notas' => $result['pago']->notas,
                'saldo_pendiente' => (float) ($credito->saldo_pendiente ?? 0),
                'num_pago' => $numPago,
                'total_pagos' => $totalPagos,
            ],
        ], 201);
    }
}
