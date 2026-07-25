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
        ]);

        return DB::transaction(function () use ($data) {
            $periodo = NominaPeriodo::create([
                'fecha_inicio' => $data['fecha_inicio'],
                'fecha_fin' => $data['fecha_fin'],
                'registrado_por' => auth()->id(),
            ]);

            $empleados = Empleado::where('activo', true)->get();
            $totalDispersado = 0;

            foreach ($empleados as $empleado) {
                $retencion = $empleado->porcentaje_ahorro
                    ? round($empleado->sueldo_base * ($empleado->porcentaje_ahorro / 100), 2)
                    : 0;
                $neto = $empleado->sueldo_base - $retencion;

                NominaDetalle::create([
                    'periodo_id' => $periodo->id,
                    'empleado_id' => $empleado->id,
                    'sueldo_bruto' => $empleado->sueldo_base,
                    'retencion_ahorro' => $retencion,
                    'sueldo_neto' => $neto,
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
