<?php

namespace App\Http\Controllers;

use App\Models\Inversionista;
use App\Services\CapitalService;
use App\Services\InversionistaImportService;
use App\Services\FlujoCajaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InversionistaController extends Controller
{
    public function index(Request $request)
    {
        $inversionistas = Inversionista::with([
            'aportaciones' => fn ($q) => $q->orderBy('fecha'),
            'liquidaciones.solicitadoPor:id,name',
            'liquidaciones.confirmadoPor:id,name',
            'reactivaciones.realizadoPor:id,name',
        ])->get();
        $carteraActivaTotal = (float) \App\Models\Credito::where('estado', 'Activo')->sum('saldo_pendiente');

        $items = $inversionistas->map(function ($inv) {
            $aportado = (float) $inv->aportaciones->where('tipo', 'Aportacion')->sum('monto');
            $retirado = (float) $inv->aportaciones->where('tipo', 'Retiro')->sum('monto');
            $rendimiento = (float) $inv->aportaciones->where('tipo', 'Rendimiento')->sum('monto');
            $saldoCapital = $aportado - $retirado;

            return array_merge($inv->toArray(), [
                'saldo_capital' => round($saldoCapital, 2),
                'rendimiento_mensual' => $inv->calcularRendimientoMensual($saldoCapital),
                'total_aportaciones' => round($aportado, 2),
                'total_retiros' => round($retirado, 2),
                'total_rendimientos' => round($rendimiento, 2),
                'liquidacion_pendiente' => $inv->liquidaciones->firstWhere('estado', 'Pendiente'),
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
            'puede_liquidar' => $request->user()?->hasPermission('inversionistas.manage') ?? false,
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
            'tasa_mensual' => 'sometimes|numeric|min:0|max:100',
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
            'tasa_mensual' => 'sometimes|numeric|min:0|max:100',
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
            try {
                $aportacion = $capitalService->registrarPagoRendimiento((int) $id, $data);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return response()->json(['message' => 'Pago de rendimiento registrado', 'data' => $aportacion], 201);
        }

        try {
            $aportacion = $capitalService->registrarAportacion((int) $id, $data);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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

        try {
            $aportacion = $capitalService->registrarPagoRendimiento((int) $id, $data);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json([
            'message' => 'Pago de rendimiento registrado exitosamente',
            'data' => $aportacion,
        ], 201);
    }

    public function liquidacion(Request $request, $id, CapitalService $capitalService)
    {
        $data = $request->validate([
            'rendimiento_final' => 'required|numeric|min:0|decimal:0,2',
            'fecha' => 'required|date|before_or_equal:today',
            'cuenta' => ['required', 'string', Rule::in(FlujoCajaService::CUENTAS)],
            'notas' => 'nullable|string|max:1000',
        ]);

        try {
            $liquidacion = $capitalService->solicitarLiquidacion((int) $id, $data);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Liquidación enviada a confirmación de Caja.',
            'data' => $liquidacion,
        ], 201);
    }

    public function ajustarCapital(Request $request, $id, CapitalService $capitalService)
    {
        $data = $request->validate([
            'total_aportaciones' => 'required|numeric|min:0|decimal:0,2',
            'saldo_capital' => 'required|numeric|min:0|decimal:0,2|lte:total_aportaciones',
            'fecha' => 'required|date|before_or_equal:today',
            'motivo' => 'required|string|min:5|max:500',
        ]);

        try {
            $inversionista = $capitalService->ajustarCapitalInversionista((int) $id, $data);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Capital del inversionista corregido con trazabilidad contable.',
            'data' => $inversionista,
        ]);
    }

    public function reactivar(Request $request, $id, CapitalService $capitalService)
    {
        $data = $request->validate([
            'fecha' => 'required|date|before_or_equal:today',
            'motivo' => 'required|string|min:5|max:500',
        ]);

        try {
            $reactivacion = $capitalService->reactivarInversionista((int) $id, $data);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Inversionista reactivado. Puede iniciar un nuevo ciclo de inversión.',
            'data' => $reactivacion,
        ]);
    }

    public function import(Request $request, InversionistaImportService $service)
    {
        $data = $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.nombre' => 'required|string|max:255',
            'rows.*.inversion_inicial' => 'nullable|numeric',
            'rows.*.total_excel' => 'nullable|numeric',
            'rows.*.rendimientos' => 'array',
            'rows.*.rendimientos.*.fecha' => 'required|date',
            'rows.*.rendimientos.*.monto' => 'required|numeric',
        ]);

        $result = $service->importar($data['rows']);

        return response()->json([
            'message' => "Importación completada: {$result['created']} creado(s), {$result['updated']} actualizado(s).",
            ...$result,
        ], empty($result['errors']) ? 200 : 207);
    }
}
