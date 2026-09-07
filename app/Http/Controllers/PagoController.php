<?php

namespace App\Http\Controllers;

use App\Models\Credito;
use App\Models\Pago;
use App\Http\Requests\StorePagoRequest;
use App\Services\MoraCalculationService;
use App\Services\PagoService;
use App\Services\DistribucionCreditoGrupalService;
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
        $credito = Credito::with(['cliente', 'grupo', 'asesor', 'distribucionesIntegrantes'])->findOrFail($numProg);
        try {
            $result = $this->pagoService->registrar($credito, $request->validated());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $credito = $credito->fresh()->load(['cliente', 'grupo', 'asesor', 'pagos', 'distribucionesIntegrantes']);

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
                'beneficiario' => $result['pago']->id_cliente_integrante
                    ? ($credito->distribucionesIntegrantes->firstWhere('id_cliente', $result['pago']->id_cliente_integrante)?->nombre_cliente ?? 'Integrante')
                    : ($credito->tipo_credito === 'Grupal'
                        ? ($credito->grupo?->nombre_grupo ?? 'Grupo')
                        : ($credito->cliente?->nombre_completo ?? 'Cliente')),
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

    public function update(Request $request, $numProg, Pago $pago)
    {
        $credito = Credito::with(['cliente', 'grupo', 'asesor'])->findOrFail($numProg);
        $this->validarPagoDelCredito($credito, $pago);

        if ($pago->tipo !== 'Abono') {
            return response()->json(['message' => 'Solo los abonos pueden editarse desde este historial.'], 422);
        }

        $hora = $request->input('hora');
        if (is_string($hora) && preg_match('/^\d{2}:\d{2}$/', $hora)) {
            $request->merge(['hora' => "{$hora}:00"]);
        }

        $data = $request->validate([
            'monto' => ['required', 'numeric', 'min:0.01'],
            'fecha' => ['required', 'date'],
            'hora' => ['nullable', 'date_format:H:i:s'],
            'metodo_pago' => ['required', 'in:Efectivo,Transferencia,Otro'],
            'notas' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $pago = $this->pagoService->actualizarAbono($credito, $pago, $data);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Abono actualizado exitosamente.',
            'data' => $pago,
        ]);
    }

    public function destroy($numProg, Pago $pago)
    {
        $credito = Credito::with(['cliente', 'grupo', 'asesor'])->findOrFail($numProg);
        $this->validarPagoDelCredito($credito, $pago);

        try {
            $this->pagoService->eliminarAbono($credito, $pago);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Abono eliminado. El crédito volvió a quedar pendiente en la ruta.',
        ]);
    }

    public function distribuirGrupal(Request $request, $numProg, DistribucionCreditoGrupalService $service)
    {
        $data = $request->validate(['distribucion' => ['required', 'array'], 'distribucion.*.id_cliente_integrante' => ['required', 'string'], 'distribucion.*.monto' => ['required', 'numeric', 'min:0']]);
        try {
            $service->asignarAbonos(Credito::findOrFail($numProg), $data['distribucion']);
            return response()->json(['message' => 'Abonos grupales distribuidos correctamente.']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function validarPagoDelCredito(Credito $credito, Pago $pago): void
    {
        if ((int) $pago->num_prog !== (int) $credito->num_prog) {
            abort(404);
        }
    }
}
