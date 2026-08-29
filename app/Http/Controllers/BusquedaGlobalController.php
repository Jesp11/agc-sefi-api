<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Credito;
use App\Models\Grupo;
use App\Models\Asesor;
use App\Models\Inversionista;
use App\Support\RoleHelper;
use Illuminate\Http\Request;

class BusquedaGlobalController extends Controller
{
    public function buscar(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([
                'clientes' => [],
                'creditos' => [],
                'grupos' => [],
                'asesores' => [],
                'inversionistas' => [],
            ]);
        }

        $user = auth()->user();
        $isField = $user && RoleHelper::isFieldLike($user->role?->nombre);
        $idAsesor = $user?->id_asesor;

        // 1. Clientes
        $clientesQuery = Cliente::with(['asesor:id_asesor,nombre_asesor'])
            ->where(function ($query) use ($q) {
                $query->where('nombre_completo', 'like', "%{$q}%")
                    ->orWhere('curp', 'like', "%{$q}%")
                    ->orWhere('telefono', 'like', "%{$q}%")
                    ->orWhere('id_cliente', 'like', "%{$q}%");
            });

        if ($isField && $idAsesor) {
            $clientesQuery->where('id_asesor', $idAsesor);
        }

        $clientes = $clientesQuery->limit(6)->get()->map(function ($c) {
            return [
                'id_cliente' => $c->id_cliente,
                'nombre_completo' => $c->nombre_completo,
                'curp' => $c->curp,
                'telefono' => $c->telefono,
                'estatus' => $c->estatus,
                'asesor' => $c->asesor?->nombre_asesor,
            ];
        });

        // 2. Créditos
        $creditosQuery = Credito::with(['cliente:id_cliente,nombre_completo', 'grupo:id,nombre_grupo', 'asesor:id_asesor,nombre_asesor'])
            ->where(function ($query) use ($q) {
                $query->where('num_prog', 'like', "%{$q}%")
                    ->orWhere('tipo_credito', 'like', "%{$q}%")
                    ->orWhere('estado', 'like', "%{$q}%")
                    ->orWhereHas('cliente', function ($sub) use ($q) {
                        $sub->where('nombre_completo', 'like', "%{$q}%");
                    })
                    ->orWhereHas('grupo', function ($sub) use ($q) {
                        $sub->where('nombre_grupo', 'like', "%{$q}%");
                    });
            });

        if ($isField && $idAsesor) {
            $creditosQuery->where('id_asesor', $idAsesor);
        }

        $creditos = $creditosQuery->limit(6)->get()->map(function ($cr) {
            return [
                'num_prog' => $cr->num_prog,
                'id_cliente' => $cr->id_cliente,
                'nombre_cliente' => $cr->cliente?->nombre_completo,
                'id_grupo' => $cr->id_grupo,
                'nombre_grupo' => $cr->grupo?->nombre_grupo,
                'monto_otorgado' => $cr->monto_otorgado,
                'total' => $cr->total,
                'estado' => $cr->estado,
                'tipo_credito' => $cr->tipo_credito,
                'ciclo' => $cr->ciclo,
            ];
        });

        // 3. Grupos
        $gruposQuery = Grupo::withCount('clientes')
            ->where('nombre_grupo', 'like', "%{$q}%");

        if ($isField && $idAsesor) {
            $gruposQuery->where('id_asesor', $idAsesor);
        }

        $grupos = $gruposQuery->limit(6)->get()->map(function ($g) {
            return [
                'id' => $g->id,
                'nombre_grupo' => $g->nombre_grupo,
                'total_clientes' => $g->clientes_count,
            ];
        });

        // 4. Asesores
        $asesoresQuery = Asesor::where(function ($query) use ($q) {
            $query->where('nombre_asesor', 'like', "%{$q}%")
                ->orWhere('telefono', 'like', "%{$q}%")
                ->orWhere('curp', 'like', "%{$q}%");
        });
        $asesores = (!$isField ? $asesoresQuery->limit(4)->get()->map(function ($a) {
            return [
                'id_asesor' => $a->id_asesor,
                'nombre_asesor' => $a->nombre_asesor,
                'telefono' => $a->telefono,
                'rol_laboral' => $a->rol_laboral,
            ];
        }) : collect([]));

        // 5. Inversionistas (Admin only)
        $inversionistas = collect([]);
        if (!$isField) {
            $inversionistas = Inversionista::where(function ($query) use ($q) {
                $query->where('nombre', 'like', "%{$q}%")
                    ->orWhere('contacto', 'like', "%{$q}%")
                    ->orWhere('telefono', 'like', "%{$q}%");
            })->limit(4)->get()->map(function ($inv) {
                return [
                    'id' => $inv->id,
                    'nombre' => $inv->nombre,
                    'contacto' => $inv->contacto,
                    'telefono' => $inv->telefono,
                    'activo' => $inv->activo,
                ];
            });
        }

        return response()->json([
            'clientes' => $clientes,
            'creditos' => $creditos,
            'grupos' => $grupos,
            'asesores' => $asesores,
            'inversionistas' => $inversionistas,
        ]);
    }
}
