<?php

namespace App\Http\Controllers;

use App\Models\ConfirmacionMovimiento;
use App\Services\FlujoCajaService;
use App\Support\RoleHelper;
use Illuminate\Http\Request;

class ConfirmacionMovimientoController extends Controller
{
    public function index(Request $request)
    {
        $fecha = $request->validate(['fecha' => ['nullable', 'date']])['fecha'] ?? now()->toDateString();

        $foliosConDesembolsoEnProceso = ConfirmacionMovimiento::whereIn('estado', ['Pendiente', 'EntregadoGestor'])
            ->where('categoria', 'Renovacion')
            ->whereNotNull('num_prog')
            ->pluck('num_prog')
            ->map(fn ($folio) => (int) $folio)
            ->all();

        $movimientos = ConfirmacionMovimiento::with(['asesor', 'credito.cliente', 'credito.grupo'])
            ->where(function ($query) {
                $query->where('estado', 'Pendiente')
                    ->orWhere(function ($renovaciones) {
                        $renovaciones->where('categoria', 'Renovacion')
                            ->whereNotNull('movimiento_caja_id')
                            ->whereIn('estado', ['EntregadoGestor', 'Confirmado', 'PendienteReintegro', 'Reintegrado', 'Reprogramado', 'Cancelado']);
                    });
            })
            ->orderBy('fecha')->orderBy('id')->get()
            ->filter(function (ConfirmacionMovimiento $movimiento) use ($fecha, $foliosConDesembolsoEnProceso) {
                if ($movimiento->fecha?->toDateString() === $fecha) {
                    return true;
                }

                // Los pendientes que aún requieren recuperar o volver a enviar
                // efectivo no se ocultan al pasar de día.
                return $movimiento->categoria === 'Renovacion'
                    && in_array($movimiento->estado, ['PendienteReintegro', 'Reintegrado'], true)
                    && $movimiento->credito?->estado === 'PendienteDesembolso'
                    && ($movimiento->estado !== 'Reintegrado'
                        || ! in_array((int) $movimiento->num_prog, $foliosConDesembolsoEnProceso, true));
            })
            ->values();

        $movimientos->each(function (ConfirmacionMovimiento $movimiento) use ($foliosConDesembolsoEnProceso) {
            $movimiento->setAttribute('puede_reprogramar', $movimiento->estado === 'Reintegrado'
                && ! in_array((int) $movimiento->num_prog, $foliosConDesembolsoEnProceso, true));
        });

        return response()->json($movimientos);
    }

    public function confirmar(ConfirmacionMovimiento $confirmacion, FlujoCajaService $flujoCaja)
    {
        try {
            $movimiento = $flujoCaja->confirmarEgresoPendiente($confirmacion);
            $message = $movimiento->estado === 'EntregadoGestor'
                ? 'Entrega al gestor confirmada y aplicada a caja. Falta confirmar el desembolso al cliente.'
                : 'Egreso confirmado y aplicado a caja.';

            return response()->json(['message' => $message, 'data' => $movimiento]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Renovaciones entregadas al gestor para seguimiento en el reporte diario. */
    public function pendientesGestor(Request $request)
    {
        $user = $request->user()->loadMissing('role');
        if (! RoleHelper::isFieldLike($user->role?->nombre) || ! $user->id_asesor) {
            abort(403, 'Sólo los gestores pueden consultar estos desembolsos.');
        }

        $fecha = $request->validate(['fecha' => ['nullable', 'date']])['fecha'] ?? now()->toDateString();

        return response()->json(ConfirmacionMovimiento::with(['credito.cliente', 'credito.grupo'])
            ->whereDate('fecha', $fecha)
            ->where('categoria', 'Renovacion')
            ->where('id_asesor', $user->id_asesor)
            ->whereNotNull('movimiento_caja_id')
            ->whereIn('estado', ['EntregadoGestor', 'Confirmado', 'PendienteReintegro', 'Reintegrado', 'Cancelado'])
            ->orderBy('fecha')
            ->orderBy('id')
            ->get());
    }

    /** El gestor confirma que entregó al cliente un desembolso de renovación. */
    public function confirmarDesembolsoGestor(
        Request $request,
        ConfirmacionMovimiento $confirmacion,
        FlujoCajaService $flujoCaja,
    ) {
        $user = $request->user()->loadMissing('role');
        if (! RoleHelper::isFieldLike($user->role?->nombre)
            || ! $user->id_asesor
            || (int) $confirmacion->id_asesor !== (int) $user->id_asesor) {
            abort(403, 'No puedes confirmar este desembolso.');
        }

        try {
            return response()->json([
                'message' => 'Desembolso al cliente confirmado.',
                'data' => $flujoCaja->confirmarDesembolsoRenovacionPorGestor($confirmacion),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** El gestor asignado deja constancia de que no logró entregar el desembolso. */
    public function cancelarDesembolsoGestor(
        Request $request,
        ConfirmacionMovimiento $confirmacion,
        FlujoCajaService $flujoCaja,
    ) {
        $user = $request->user()->loadMissing('role');
        if (! RoleHelper::isFieldLike($user->role?->nombre)
            || ! $user->id_asesor
            || (int) $confirmacion->id_asesor !== (int) $user->id_asesor) {
            abort(403, 'No puedes cancelar este desembolso.');
        }

        try {
            return response()->json([
                'message' => 'Desembolso cancelado. Queda pendiente que Gerencia o Contabilidad confirme el reintegro en caja.',
                'data' => $flujoCaja->cancelarDesembolsoRenovacionPorGestor($confirmacion),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Contabilidad/Gerencia registra que recibió de vuelta el efectivo del gestor. */
    public function confirmarReintegro(ConfirmacionMovimiento $confirmacion, FlujoCajaService $flujoCaja)
    {
        try {
            return response()->json([
                'message' => 'Reintegro confirmado e ingreso registrado en caja.',
                'data' => $flujoCaja->confirmarReintegroRenovacion($confirmacion),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Gerencia/Contabilidad vuelve a programar una renovación ya reintegrada. */
    public function reprogramarDesembolso(Request $request, ConfirmacionMovimiento $confirmacion, FlujoCajaService $flujoCaja)
    {
        $fecha = $request->validate(['fecha' => ['nullable', 'date']])['fecha'] ?? null;

        try {
            return response()->json([
                'message' => 'Nuevo desembolso programado. Confirma la entrega al gestor cuando se le entregue el efectivo.',
                'data' => $flujoCaja->reprogramarDesembolsoRenovacion($confirmacion, $fecha),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancelar(ConfirmacionMovimiento $confirmacion)
    {
        if ($confirmacion->estado !== 'Pendiente') {
            return response()->json(['message' => 'Este movimiento ya fue atendido.'], 422);
        }
        $confirmacion->update(['estado' => 'Cancelado', 'confirmado_por' => auth()->id(), 'confirmado_at' => now()]);
        return response()->json(['message' => 'Movimiento pendiente cancelado.']);
    }
}
