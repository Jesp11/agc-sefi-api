<?php

namespace App\Http\Controllers;

use App\Models\Credito;
use App\Services\CarteraService;
use App\Services\MoraCalculationService;
use Illuminate\Http\Request;

class CarteraController extends Controller
{
    public function __construct(
        private MoraCalculationService $moraService,
        private CarteraService $carteraService
    ) {}

    public function cobrosDelDia(Request $request)
    {
        return response()->json($this->carteraService->cobrosDelDia(
            $request->query('fecha'),
            $this->scopedAsesorId($request)
        ));
    }

    public function activa(Request $request)
    {
        $tipo = $request->query('tipo');
        $idAsesor = $this->scopedAsesorId($request);

        // Solo cartera activa: EnMora se lista en /cartera/mora
        $query = Credito::with(['cliente', 'grupo', 'asesor'])
            ->where('estado', 'Activo');

        if ($tipo === 'individual') {
            $query->where('tipo_credito', 'Individual');
        } elseif ($tipo === 'grupal') {
            $query->where('tipo_credito', 'Grupal');
        }

        if ($idAsesor) {
            $query->where('id_asesor', $idAsesor);
        }

        return response()->json($query->paginate($request->query('per_page', 15)));
    }

    public function mora(Request $request)
    {
        $tipo = $request->query('tipo');
        $idAsesor = $this->scopedAsesorId($request);

        $query = Credito::with(['cliente', 'grupo', 'asesor', 'pagos'])
            ->where('estado', 'EnMora');

        if ($tipo === 'individual') {
            $query->where('tipo_credito', 'Individual');
        } elseif ($tipo === 'grupal') {
            $query->where('tipo_credito', 'Grupal');
        }

        if ($idAsesor) {
            $query->where('id_asesor', $idAsesor);
        }

        $creditos = $query->paginate($request->query('per_page', 15));

        $creditos->getCollection()->transform(function ($credito) {
            return array_merge($credito->toArray(), [
                'mora' => $this->moraService->calculate($credito),
                'dias_mora' => $this->moraService->calculate($credito)['dias_mora'],
            ]);
        });

        return response()->json($creditos);
    }

    public function cerrados(Request $request)
    {
        $tipo = $request->query('tipo');
        $idAsesor = $this->scopedAsesorId($request);

        $query = Credito::with(['cliente', 'grupo', 'asesor'])
            ->whereIn('estado', ['CerradoSinRenovacion', 'Finalizado']);

        if ($tipo === 'individual') {
            $query->where('tipo_credito', 'Individual');
        } elseif ($tipo === 'grupal') {
            $query->where('tipo_credito', 'Grupal');
        }

        if ($idAsesor) {
            $query->where('id_asesor', $idAsesor);
        }

        return response()->json($query->paginate($request->query('per_page', 15)));
    }

    public function enviarMora($numProg)
    {
        $credito = Credito::findOrFail($numProg);
        if ($denied = $this->denyIfNotOwnCartera($credito)) {
            return $denied;
        }

        try {
            $credito = $this->carteraService->enviarAMora($credito);
            return response()->json([
                'message' => 'Crédito enviado a cartera en mora',
                'data' => $credito,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cerrar($numProg)
    {
        $credito = Credito::findOrFail($numProg);
        if ($denied = $this->denyIfNotOwnCartera($credito)) {
            return $denied;
        }

        try {
            $credito = $this->carteraService->cerrarSinRenovacion($credito);
            return response()->json([
                'message' => 'Crédito cerrado sin renovación',
                'data' => $credito,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reactivarCredito($numProg)
    {
        $credito = Credito::findOrFail($numProg);

        try {
            $credito = $this->carteraService->reactivar($credito);
            return response()->json([
                'message' => 'Crédito reactivado',
                'data' => $credito,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function moraDetalle($numProg)
    {
        $credito = Credito::with(['cliente', 'grupo', 'asesor', 'pagos'])->findOrFail($numProg);
        return response()->json([
            'credito' => $credito,
            'mora' => $this->moraService->calculate($credito),
            'pagos' => $credito->pagos,
        ]);
    }

    private function scopedAsesorId(Request $request): ?int
    {
        $user = auth()->user();
        if ($user && $user->role?->nombre === 'asesor') {
            return $user->id_asesor;
        }
        return $request->query('id_asesor') ? (int) $request->query('id_asesor') : null;
    }

    /** Un asesor solo puede modificar créditos de su propia cartera. */
    private function denyIfNotOwnCartera(Credito $credito): ?\Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        if ($user && $user->role?->nombre === 'asesor' && (int) $credito->id_asesor !== (int) $user->id_asesor) {
            return response()->json(['message' => 'No puedes modificar créditos de otra cartera.'], 403);
        }
        return null;
    }
}
