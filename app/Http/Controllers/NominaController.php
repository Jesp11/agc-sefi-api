<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\NominaPeriodo;
use App\Models\NominaDetalle;
use App\Models\AhorroEmpleado;
use App\Models\AhorroMovimiento;
use App\Models\MovimientoCapital;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NominaController extends Controller
{
    public function index()
    {
        return response()->json(NominaPeriodo::with('detalles.empleado')->orderByDesc('fecha_inicio')->paginate(10));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            'ajustes' => 'nullable|array',
            'ajustes.*.empleado_id' => 'required|integer|exists:empleados,id',
            'ajustes.*.percepciones' => 'nullable|array',
            'ajustes.*.percepciones.*.concepto' => 'required_with:ajustes.*.percepciones|string|max:255',
            'ajustes.*.percepciones.*.monto' => 'required_with:ajustes.*.percepciones|numeric|min:0',
            'ajustes.*.deducciones' => 'nullable|array',
            'ajustes.*.deducciones.*.concepto' => 'required_with:ajustes.*.deducciones|string|max:255',
            'ajustes.*.deducciones.*.monto' => 'required_with:ajustes.*.deducciones|numeric|min:0',
        ]);

        return DB::transaction(function () use ($data) {
            $periodo = NominaPeriodo::create([
                'fecha_inicio' => $data['fecha_inicio'],
                'fecha_fin' => $data['fecha_fin'],
                'registrado_por' => auth()->id(),
            ]);

            $empleados = Empleado::where('activo', true)->get();
            $ajustesPeriodo = collect($data['ajustes'] ?? [])->keyBy('empleado_id');
            $totalDispersado = 0;

            foreach ($empleados as $empleado) {
                $percepcionesBase = collect($empleado->percepciones_config ?? [])
                    ->map(fn ($item) => ['concepto' => $item['concepto'], 'monto' => round((float) $item['monto'], 2)]);
                $deduccionesBase = collect($empleado->deducciones_config ?? [])
                    ->map(fn ($item) => ['concepto' => $item['concepto'], 'monto' => round((float) $item['monto'], 2)]);
                $ajuste = $ajustesPeriodo->get($empleado->id, []);
                $percepcionesPeriodo = collect($ajuste['percepciones'] ?? [])
                    ->map(fn ($item) => ['concepto' => $item['concepto'], 'monto' => round((float) $item['monto'], 2)]);
                $deduccionesPeriodo = collect($ajuste['deducciones'] ?? [])
                    ->map(fn ($item) => ['concepto' => $item['concepto'], 'monto' => round((float) $item['monto'], 2)]);

                $totalPercepciones = round((float) $percepcionesBase->sum('monto') + (float) $percepcionesPeriodo->sum('monto'), 2);
                $totalDeduccionesExtras = round((float) $deduccionesBase->sum('monto') + (float) $deduccionesPeriodo->sum('monto'), 2);
                $retencion = $empleado->porcentaje_ahorro
                    ? round($empleado->sueldo_base * ($empleado->porcentaje_ahorro / 100), 2)
                    : 0;
                $neto = round($empleado->sueldo_base + $totalPercepciones - $retencion - $totalDeduccionesExtras, 2);

                NominaDetalle::create([
                    'periodo_id' => $periodo->id,
                    'empleado_id' => $empleado->id,
                    'sueldo_bruto' => $empleado->sueldo_base,
                    'total_percepciones' => $totalPercepciones,
                    'retencion_ahorro' => $retencion,
                    'total_deducciones' => $totalDeduccionesExtras,
                    'sueldo_neto' => $neto,
                    'detalle_ajustes' => [
                        'percepciones_config' => $percepcionesBase->values()->all(),
                        'deducciones_config' => $deduccionesBase->values()->all(),
                        'percepciones_periodo' => $percepcionesPeriodo->values()->all(),
                        'deducciones_periodo' => $deduccionesPeriodo->values()->all(),
                    ],
                ]);

                if ($retencion > 0) {
                    $ahorro = AhorroEmpleado::firstOrCreate(['empleado_id' => $empleado->id], ['saldo' => 0]);
                    $ahorro->increment('saldo', $retencion);
                    AhorroMovimiento::create([
                        'ahorro_id' => $ahorro->id,
                        'tipo' => 'Deduccion',
                        'monto' => $retencion,
                        'fecha' => $data['fecha_fin'],
                        'notas' => "Nómina periodo #{$periodo->id}",
                    ]);
                }

                $totalDispersado += $neto;
            }

            $periodo->update(['total_dispersado' => $totalDispersado]);

            MovimientoCapital::create([
                'tipo' => 'Nomina',
                'monto' => -$totalDispersado,
                'referencia' => "NOM-{$periodo->id}",
                'fecha' => $data['fecha_fin'],
                'descripcion' => "Dispersión nómina {$data['fecha_inicio']} - {$data['fecha_fin']}",
                'registrado_por' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'Nómina procesada',
                'data' => $periodo->load('detalles.empleado'),
            ], 201);
        });
    }
}
