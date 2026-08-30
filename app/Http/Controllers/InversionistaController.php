<?php

namespace App\Http\Controllers;

use App\Models\Inversionista;
use App\Services\CapitalService;
use Illuminate\Http\Request;

class InversionistaController extends Controller
{
    public function index()
    {
        $inversionistas = Inversionista::with(['aportaciones' => fn ($q) => $q->orderBy('fecha')])->get();
        $carteraActivaTotal = (float) \App\Models\Credito::where('estado', 'Activo')->sum('saldo_pendiente');

        $items = $inversionistas->map(function ($inv) {
            $aportado = (float) $inv->aportaciones->where('tipo', 'Aportacion')->sum('monto');
            $retirado = (float) $inv->aportaciones->where('tipo', 'Retiro')->sum('monto');
            $rendimiento = (float) $inv->aportaciones->where('tipo', 'Rendimiento')->sum('monto');
            $saldoCapital = $aportado - $retirado;

            return array_merge($inv->toArray(), [
                'saldo_capital' => round($saldoCapital, 2),
                'total_aportaciones' => round($aportado, 2),
                'total_retiros' => round($retirado, 2),
                'total_rendimientos' => round($rendimiento, 2),
            ]);
        });

        $totalCapital = (float) $items->sum('saldo_capital');
        $totalRendimientos = (float) $items->sum('total_rendimientos');
        $inversionistasActivos = $items->where('saldo_capital', '>', 0)->count();
        $ratioCobertura = $totalCapital > 0 ? round($carteraActivaTotal / $totalCapital, 2) : 0;

        return response()->json([
            'data' => $items,
            'resumen' => [
                'capital_total' => round($totalCapital, 2),
                'rendimientos_total' => round($totalRendimientos, 2),
                'inversionistas_activos' => $inversionistasActivos,
                'cartera_activa_total' => round($carteraActivaTotal, 2),
                'ratio_cobertura' => $ratioCobertura,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'tipo_entidad' => 'nullable|string|max:100',
            'origen_fondeo' => 'nullable|string|max:255',
            'contacto' => 'nullable|string',
            'telefono' => 'nullable|string',
            'email' => 'nullable|email',
            'tasa_preferencial' => 'boolean',
        ]);

        $inv = Inversionista::create($data);
        return response()->json(['message' => 'Inversionista creado', 'data' => $inv], 201);
    }

    public function update(Request $request, $id)
    {
        $inv = Inversionista::findOrFail($id);
        $inv->update($request->validate([
            'nombre' => 'sometimes|string|max:255',
            'tipo_entidad' => 'nullable|string|max:100',
            'origen_fondeo' => 'nullable|string|max:255',
            'contacto' => 'nullable|string',
            'telefono' => 'nullable|string',
            'email' => 'nullable|email',
            'tasa_preferencial' => 'boolean',
            'activo' => 'boolean',
        ]));
        return response()->json(['message' => 'Actualizado', 'data' => $inv]);
    }

    public function aportacion(Request $request, $id, CapitalService $capitalService)
    {
        $data = $request->validate([
            'monto' => 'required|numeric|min:0.01',
            'fecha' => 'required|date',
            'tipo' => 'in:Aportacion,Retiro,Rendimiento',
            'notas' => 'nullable|string',
        ]);

        if (($data['tipo'] ?? '') === 'Rendimiento') {
            $aportacion = $capitalService->registrarPagoRendimiento((int) $id, $data);
            return response()->json(['message' => 'Pago de rendimiento registrado', 'data' => $aportacion], 201);
        }

        $aportacion = $capitalService->registrarAportacion((int) $id, $data);
        return response()->json(['message' => 'Aportación registrada', 'data' => $aportacion], 201);
    }

    public function pagoRendimiento(Request $request, $id, CapitalService $capitalService)
    {
        $data = $request->validate([
            'monto' => 'required|numeric|min:0.01',
            'fecha' => 'required|date',
            'cuenta' => 'nullable|string|max:50',
            'concepto' => 'nullable|string|max:255',
            'notas' => 'nullable|string|max:500',
        ]);

        $aportacion = $capitalService->registrarPagoRendimiento((int) $id, $data);
        return response()->json([
            'message' => 'Pago de rendimiento registrado exitosamente',
            'data' => $aportacion,
        ], 201);
    }
}
