<?php

namespace App\Http\Controllers;

use App\Models\NominaPeriodo;
use App\Models\NominaDetalle;
use App\Models\AhorroPersonal;
use App\Models\AhorroPersonalMovimiento;
use App\Models\Asesor;
use App\Models\MovimientoCapital;
use App\Services\FlujoCajaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NominaController extends Controller
{
    public function index()
    {
        return response()->json(NominaPeriodo::with(['detalles.asesor', 'detalles.empleado'])->orderByDesc('fecha_inicio')->paginate(10));
    }

    public function store(Request $request, FlujoCajaService $flujoCajaService)
    {
        $data = $request->validate([
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            'referencia' => 'nullable|string|max:255',
            'firma_director_administrativo' => 'nullable|string|max:255',
            'firma_director_operativo' => 'nullable|string|max:255',
            'empleados' => 'required|array|min:1',
            'empleados.*.asesor_id' => 'required|integer|distinct|exists:asesores,id',
            'empleados.*.pago_base' => 'required|numeric|min:0',
            'empleados.*.despensa' => 'required|numeric|min:0',
            'empleados.*.apoyo_transporte' => 'required|numeric|min:0',
            'empleados.*.ahorro' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $periodo = NominaPeriodo::create([
                'fecha_inicio' => $data['fecha_inicio'],
                'fecha_fin' => $data['fecha_fin'],
                'referencia' => $data['referencia'] ?? null,
                'firma_director_administrativo' => $data['firma_director_administrativo'] ?? null,
                'firma_director_operativo' => $data['firma_director_operativo'] ?? null,
                'registrado_por' => auth()->id(),
            ]);

            $totalDispersado = 0;
            $pagosNomina = [];
            foreach ($data['empleados'] as $empData) {
                $percepciones = $empData['pago_base'] + $empData['despensa'] + $empData['apoyo_transporte'];
                $retencion = $empData['ahorro'];
                $deducciones = $retencion;
                $neto = $percepciones - $deducciones;

                NominaDetalle::create([
                    'periodo_id' => $periodo->id,
                    'asesor_id' => $empData['asesor_id'],
                    'sueldo_bruto' => $empData['pago_base'],
                    'pago_base' => $empData['pago_base'],
                    'despensa' => $empData['despensa'],
                    'apoyo_transporte' => $empData['apoyo_transporte'],
                    'total_percepciones' => $percepciones,
                    'retencion_ahorro' => $retencion,
                    'total_deducciones' => $deducciones,
                    'sueldo_neto' => $neto,
                    'detalle_ajustes' => []
                ]);

                if ($retencion > 0) {
                    $ahorro = AhorroPersonal::firstOrCreate(['asesor_id' => $empData['asesor_id']], ['saldo' => 0]);
                    $ahorro->increment('saldo', $retencion);
                    AhorroPersonalMovimiento::create([
                        'ahorro_personal_id' => $ahorro->id,
                        'tipo' => 'Ingreso',
                        'monto' => $retencion,
                        'fecha' => $data['fecha_fin'],
                        'notas' => "Nómina periodo #{$periodo->id}" . ($data['referencia'] ? " Ref: {$data['referencia']}" : ""),
                        'registrado_por' => auth()->id(),
                    ]);
                }

                $totalDispersado += $neto;
                if ($neto > 0) {
                    $pagosNomina[] = [
                        'asesor_id' => $empData['asesor_id'],
                        'monto' => $neto,
                    ];
                }
            }

            $periodo->update(['total_dispersado' => $totalDispersado]);

            MovimientoCapital::create([
                'tipo' => 'Nomina',
                'monto' => -$totalDispersado,
                'referencia' => $data['referencia'] ? "NOM-{$data['referencia']}" : "NOM-{$periodo->id}",
                'fecha' => $data['fecha_fin'],
                'descripcion' => "Dispersión nómina {$data['fecha_inicio']} - {$data['fecha_fin']}",
                'registrado_por' => auth()->id(),
            ]);

            if ($totalDispersado > 0) {
                $referenciaMovimiento = $data['referencia'] ?? "Periodo #{$periodo->id}";
                $asesores = Asesor::whereIn('id', collect($pagosNomina)->pluck('asesor_id'))
                    ->pluck('nombre_asesor', 'id');

                foreach ($pagosNomina as $pago) {
                    $nombre = $asesores[$pago['asesor_id']] ?? "Empleado #{$pago['asesor_id']}";
                    $flujoCajaService->registrar([
                        'fecha' => $data['fecha_fin'],
                        'id_asesor' => $pago['asesor_id'],
                        'motivo' => "NÓMINA — {$referenciaMovimiento} — {$nombre}",
                        'tipo' => 'Egreso',
                        'monto' => $pago['monto'],
                        'categoria' => 'Nomina',
                        'referencia' => "NOM-{$periodo->id}-ASESOR-{$pago['asesor_id']}",
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Nómina procesada',
                'data' => $periodo->load('detalles')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al procesar nómina', 'error' => $e->getMessage()], 500);
        }
    }
}
