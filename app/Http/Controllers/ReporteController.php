<?php

namespace App\Http\Controllers;

use App\Support\RoleHelper;
use App\Services\AhorroPersonalService;
use App\Services\AhorroSocioService;
use App\Services\ReportService;
use Illuminate\Http\Request;

class ReporteController extends Controller
{
    public function __construct(private ReportService $reportService) {}

    private function scopedAsesorId(Request $request): ?int
    {
        $user = auth()->user();
        if ($user && RoleHelper::isFieldLike($user->role?->nombre)) {
            return $user->id_asesor;
        }
        return $request->query('id_asesor') ? (int) $request->query('id_asesor') : null;
    }

    public function diario(Request $request)
    {
        return response()->json($this->reportService->reporteDiario(
            $request->query('fecha'),
            $this->scopedAsesorId($request)
        ));
    }

    public function recibirAsesor(Request $request)
    {
        $data = $request->validate([
            'fecha' => 'required|date',
            'id_asesor' => 'required|integer|exists:asesores,id',
            'monto_recibido' => 'required|numeric|min:0',
            'notas' => 'nullable|string|max:500',
        ]);

        try {
            $recepcion = $this->reportService->registrarRecepcionAsesor(
                $data['fecha'],
                (int) $data['id_asesor'],
                (float) $data['monto_recibido'],
                $data['notas'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Recepción registrada',
            'data' => $recepcion,
        ]);
    }

    public function cartera(Request $request)
    {
        return response()->json($this->reportService->cartera(
            $request->query('tipo', 'general'),
            $this->scopedAsesorId($request)
        ));
    }

    public function asesorDiario(Request $request)
    {
        return response()->json($this->reportService->reporteDiario(
            $request->query('fecha'),
            $this->scopedAsesorId($request) ?? (int) $request->query('id_asesor')
        ));
    }

    public function asesorMora(Request $request)
    {
        return response()->json($this->reportService->moraPorAsesor(
            $this->scopedAsesorId($request) ?? ($request->query('id_asesor') ? (int) $request->query('id_asesor') : null)
        ));
    }

    public function asesorPorCerrar(Request $request)
    {
        return response()->json($this->reportService->clientesPorCerrar(
            $this->scopedAsesorId($request) ?? ($request->query('id_asesor') ? (int) $request->query('id_asesor') : null)
        ));
    }

    public function inversionistas()
    {
        return response()->json($this->reportService->reporteInversionistas());
    }

    public function cierreMensual(Request $request)
    {
        return response()->json($this->reportService->cierreMensual(
            $request->query('mes')
        ));
    }

    public function guardarCierreMensualManual(Request $request)
    {
        $data = $request->validate([
            'mes' => 'required|date_format:Y-m',
            'aumento_cartera' => 'nullable|numeric',
            'pase_a_cartera_mora' => 'nullable|numeric',
            'productividad_mensual' => 'nullable|numeric',
        ]);

        $registro = $this->reportService->guardarCierreMensualManual($data['mes'], $data);

        return response()->json([
            'message' => 'Indicadores operativos guardados',
            'data' => $registro,
        ]);
    }

    public function accionistasConfigurados()
    {
        return response()->json([
            'data' => $this->reportService->accionistasConfigurados(),
        ]);
    }

    public function guardarAccionistasConfigurados(Request $request)
    {
        $data = $request->validate([
            'accionistas' => 'required|array|min:1',
            'accionistas.*.nombre' => 'required|string|max:255',
            'accionistas.*.porcentaje' => 'required|numeric|min:0.01|max:100',
        ]);

        $total = round((float) collect($data['accionistas'])->sum(fn ($row) => (float) ($row['porcentaje'] ?? 0)), 2);
        if (abs($total - 100) > 0.01) {
            return response()->json([
                'message' => 'La suma de participaciones debe ser 100%.',
                'total' => $total,
            ], 422);
        }

        $rows = $this->reportService->guardarAccionistasConfigurados($data['accionistas']);

        return response()->json([
            'message' => 'Participaciones de accionistas guardadas',
            'data' => $rows,
        ]);
    }

    public function estadoFinancieroInversionistas(Request $request)
    {
        return response()->json($this->reportService->estadoFinancieroInversionistas(
            $request->query('fecha_inicio'),
            $request->query('fecha_fin')
        ));
    }

    public function ahorros()
    {
        return response()->json($this->reportService->reporteAhorros());
    }

    public function ahorrosSocios(Request $request, AhorroSocioService $ahorroSocioService)
    {
        $anio = (int) ($request->query('anio') ?? now()->year);
        return response()->json($ahorroSocioService->resumenAnual($anio));
    }

    public function ahorrosPersonal(Request $request, AhorroPersonalService $ahorroPersonalService)
    {
        $anio = (int) ($request->query('anio') ?? now()->year);
        return response()->json($ahorroPersonalService->resumenAnual($anio));
    }

    public function carteraAhorro()
    {
        return response()->json($this->reportService->carteraAhorro());
    }

    public function gastosOperativos(Request $request)
    {
        return response()->json($this->reportService->gastosOperativos(
            $request->query('fecha_inicio'),
            $request->query('fecha_fin'),
            $request->query('categoria'),
            $request->query('cuenta')
        ));
    }

    public function gestorSemanal(Request $request)
    {
        return response()->json($this->reportService->reporteGestor(
            'semanal',
            $this->scopedAsesorId($request) ?? ($request->query('id_asesor') ? (int) $request->query('id_asesor') : null),
            $request->query('semana_inicio')
        ));
    }

    public function gestorMensual(Request $request)
    {
        return response()->json($this->reportService->reporteGestor(
            'mensual',
            $this->scopedAsesorId($request) ?? ($request->query('id_asesor') ? (int) $request->query('id_asesor') : null),
            $request->query('mes')
        ));
    }

    public function clientesSinRenovacion(Request $request)
    {
        return response()->json($this->reportService->clientesSinRenovacion(
            $this->scopedAsesorId($request) ?? ($request->query('id_asesor') ? (int) $request->query('id_asesor') : null)
        ));
    }

    public function comparativas(Request $request)
    {
        $request->validate([
            'periodo1_inicio' => 'required|date',
            'periodo1_fin' => 'required|date',
            'periodo2_inicio' => 'required|date',
            'periodo2_fin' => 'required|date',
        ]);

        return response()->json($this->reportService->comparativas(
            $request->periodo1_inicio,
            $request->periodo1_fin,
            $request->periodo2_inicio,
            $request->periodo2_fin
        ));
    }

    public function semanal(Request $request)
    {
        return response()->json($this->reportService->reporteSemanal(
            $request->query('semana_inicio'),
            $this->scopedAsesorId($request)
        ));
    }
}
