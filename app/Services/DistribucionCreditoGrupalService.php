<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Credito;
use App\Models\Pago;
use App\Models\PagoGrupalAsignacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DistribucionCreditoGrupalService
{
    /**
     * Guarda una distribución capturada por administración. El capital es la
     * única cifra de captura: interés, total y ficha se distribuyen en
     * centavos para conciliar exactamente con el crédito grupal.
     */
    public function guardar(Credito $credito, array $integrantes): void
    {
        $clientes = $this->validarIntegrantes($credito, $integrantes);
        $capitales = collect($integrantes)->mapWithKeys(fn (array $item) => [
            (string) $item['id_cliente'] => $this->centavos($item['capital'] ?? null),
        ]);

        if ($capitales->contains(fn ($centavos) => $centavos <= 0)) {
            throw ValidationException::withMessages([
                'distribucion_integrantes' => 'Cada integrante debe tener un capital mayor a cero.',
            ]);
        }

        $capitalGrupo = $this->centavos($credito->monto_otorgado);
        if ($capitales->sum() !== $capitalGrupo) {
            throw ValidationException::withMessages([
                'distribucion_integrantes' => 'La suma de capitales debe coincidir exactamente con el monto otorgado del grupo.',
            ]);
        }
        if ($this->centavos($credito->total) !== $capitalGrupo + $this->centavos($credito->interes)) {
            throw ValidationException::withMessages([
                'total' => 'El total del crédito grupal debe ser igual al capital más el interés antes de distribuirlo.',
            ]);
        }

        $totales = $this->prorratear($capitales, $this->centavos($credito->total));
        $fichas = $this->prorratear($capitales, $this->centavos($credito->valor_ficha));

        $registros = [];
        foreach (array_values($integrantes) as $ordenCero => $item) {
            $idCliente = (string) $item['id_cliente'];
            $cliente = $clientes->get($idCliente);
            $capital = $capitales->get($idCliente);
            $total = $totales->get($idCliente);

            $registros[] = $this->registro($credito, $cliente, $ordenCero + 1, $capital, $total - $capital, $total, $fichas->get($idCliente));
        }

        $this->reemplazar($credito, $registros);
    }

    /**
     * Conserva el desglose que viene de una importación sólo si concilia en su
     * totalidad. Un archivo incompleto nunca habilita documentos legales.
     */
    public function sincronizarDesdeImportacion(Credito $credito, array $integrantes): bool
    {
        try {
            $clientes = $this->validarIntegrantes($credito, $integrantes);
            $registros = [];
            $sumaCapital = $sumaInteres = $sumaTotal = $sumaFicha = 0;

            foreach (array_values($integrantes) as $ordenCero => $item) {
                foreach (['capital', 'interes', 'total', 'valor_ficha'] as $campo) {
                    if (! array_key_exists($campo, $item) || ! is_numeric($item[$campo])) {
                        throw new \InvalidArgumentException('Desglose incompleto.');
                    }
                }
                $capital = $this->centavos($item['capital']);
                $interes = $this->centavos($item['interes']);
                $total = $this->centavos($item['total']);
                $ficha = $this->centavos($item['valor_ficha']);
                if ($capital <= 0 || $total !== $capital + $interes) {
                    throw new \InvalidArgumentException('Desglose inconsistente.');
                }
                $sumaCapital += $capital;
                $sumaInteres += $interes;
                $sumaTotal += $total;
                $sumaFicha += $ficha;
                $registros[] = $this->registro(
                    $credito,
                    $clientes->get((string) $item['id_cliente']),
                    $ordenCero + 1,
                    $capital,
                    $interes,
                    $total,
                    $ficha,
                );
            }

            if ($sumaCapital !== $this->centavos($credito->monto_otorgado)
                || $sumaInteres !== $this->centavos($credito->interes)
                || $sumaTotal !== $this->centavos($credito->total)
                || $sumaFicha !== $this->centavos($credito->valor_ficha)) {
                throw new \InvalidArgumentException('El desglose no concilia con el crédito grupal.');
            }

            $this->reemplazar($credito, $registros);
            return true;
        } catch (\Throwable) {
            $credito->distribucionesIntegrantes()->delete();
            return false;
        }
    }

    public function resumen(Credito $credito): array
    {
        $distribuciones = $credito->relationLoaded('distribucionesIntegrantes')
            ? $credito->distribucionesIntegrantes
            : $credito->distribucionesIntegrantes()->get();

        $idsGrupo = $credito->tipo_credito === 'Grupal'
            ? $credito->grupo()->first()?->clientes()->pluck('clientes.id_cliente')->map(fn ($id) => (string) $id)->sort()->values()
            : collect();
        $idsDistribucion = $distribuciones->pluck('id_cliente')->map(fn ($id) => (string) $id)->sort()->values();

        return [
            'lista' => $distribuciones->count() > 0,
            'capital_grupal' => round((float) $credito->monto_otorgado, 2),
            'capital_integrantes' => round((float) $distribuciones->sum('capital'), 2),
            'interes_grupal' => round((float) $credito->interes, 2),
            'interes_integrantes' => round((float) $distribuciones->sum('interes'), 2),
            'total_grupal' => round((float) $credito->total, 2),
            'total_integrantes' => round((float) $distribuciones->sum('total'), 2),
            'ficha_grupal' => round((float) $credito->valor_ficha, 2),
            'ficha_integrantes' => round((float) $distribuciones->sum('valor_ficha'), 2),
            'documentos_habilitados' => $credito->tipo_credito === 'Grupal'
                && $idsDistribucion->all() === $idsGrupo->all()
                && $this->centavos($distribuciones->sum('capital')) === $this->centavos($credito->monto_otorgado)
                && $this->centavos($distribuciones->sum('interes')) === $this->centavos($credito->interes)
                && $this->centavos($distribuciones->sum('total')) === $this->centavos($credito->total)
                && $this->centavos($distribuciones->sum('valor_ficha')) === $this->centavos($credito->valor_ficha),
        ];
    }

    /**
     * Desglose de cobranza por integrante. Los abonos previos que se
     * registraron para todo el grupo permanecen como no asignados: no se
     * atribuyen arbitrariamente a una persona.
     */
    public function cobranzaPorIntegrante(Credito $credito): array
    {
        $distribuciones = $credito->relationLoaded('distribucionesIntegrantes')
            ? $credito->distribucionesIntegrantes
            : $credito->distribucionesIntegrantes()->get();
        $pagos = $credito->relationLoaded('pagos')
            ? $credito->pagos
            : $credito->pagos()->get();
        $abonos = $pagos->where('tipo', 'Abono');
        $abonosPorIntegrante = $abonos
            ->filter(fn ($pago) => !empty($pago->id_cliente_integrante))
            ->groupBy('id_cliente_integrante')
            ->map(fn (Collection $items) => round((float) $items->sum('monto'), 2));
        $asignaciones = PagoGrupalAsignacion::whereIn('pago_id', $abonos->pluck('id'))->get();
        foreach ($asignaciones->groupBy('id_cliente_integrante') as $idCliente => $items) {
            $abonosPorIntegrante->put($idCliente, round((float) ($abonosPorIntegrante->get($idCliente, 0) + $items->sum('monto')), 2));
        }

        return [
            'integrantes' => $distribuciones->map(function ($integrante) use ($abonosPorIntegrante) {
                $abonado = (float) ($abonosPorIntegrante->get($integrante->id_cliente) ?? 0);
                $total = (float) $integrante->total;
                $saldo = max(0, round($total - $abonado, 2));

                return [
                    'id_cliente' => $integrante->id_cliente,
                    'abonado' => round($abonado, 2),
                    'saldo_pendiente' => $saldo,
                    'liquidado' => $saldo <= 0,
                ];
            })->values()->all(),
            'abonos_individuales' => round((float) $abonosPorIntegrante->sum(), 2),
            'abonos_grupales_sin_asignar' => round(max(0, (float) $abonos
                ->filter(fn ($pago) => empty($pago->id_cliente_integrante))
                ->sum('monto') - (float) $asignaciones->sum('monto')), 2),
        ];
    }

    public function asignarAbonos(Credito $credito, array $distribucion): void
    {
        if ($credito->tipo_credito !== 'Grupal') throw new \InvalidArgumentException('Solo aplica a créditos grupales.');
        $pagos = Pago::where('num_prog', $credito->num_prog)->where('tipo', 'Abono')->whereNull('id_cliente_integrante')->orderBy('fecha')->orderBy('id')->get();
        $integrantes = $credito->distribucionesIntegrantes()->get()->keyBy('id_cliente');
        $totalDisponible = round((float) $pagos->sum('monto'), 2);
        $totalDistribuido = round((float) collect($distribucion)->sum('monto'), 2);
        if ($totalDistribuido > $totalDisponible + 0.009) throw new \InvalidArgumentException('La distribución supera el total de abonos sin asignar.');
        foreach ($distribucion as $fila) if (!$integrantes->has($fila['id_cliente_integrante'])) throw new \InvalidArgumentException('Integrante inválido.');

        DB::transaction(function () use ($pagos, $distribucion) {
            PagoGrupalAsignacion::whereIn('pago_id', $pagos->pluck('id'))->delete();
            $restantes = collect($distribucion)->filter(fn ($fila) => (float) $fila['monto'] > 0)->map(fn ($fila) => [
                'id_cliente_integrante' => $fila['id_cliente_integrante'], 'monto' => round((float) $fila['monto'], 2),
            ])->values();
            $registros = [];
            foreach ($pagos as $pago) {
                $disponible = (float) $pago->monto;
                while ($disponible > 0.009 && $restantes->isNotEmpty()) {
                    $fila = $restantes->first(); $aplicado = min($disponible, $fila['monto']);
                    $registros[] = ['pago_id' => $pago->id, 'id_cliente_integrante' => $fila['id_cliente_integrante'], 'monto' => $aplicado, 'created_at' => now(), 'updated_at' => now()];
                    $disponible = round($disponible - $aplicado, 2); $fila['monto'] = round($fila['monto'] - $aplicado, 2);
                    $fila['monto'] <= 0.009 ? $restantes->shift() : $restantes->put(0, $fila);
                }
            }
            if ($registros) PagoGrupalAsignacion::insert($registros);
        });
    }

    /** Distribuye un abono grupal nuevo de forma proporcional a la ficha individual pendiente. */
    public function asignarPagoAutomaticamente(Credito $credito, Pago $pago): void
    {
        if ($credito->tipo_credito !== 'Grupal' || $pago->tipo !== 'Abono' || $pago->id_cliente_integrante) return;
        $integrantes = $credito->distribucionesIntegrantes()->get();
        if ($integrantes->isEmpty()) return;
        $cobranza = $this->cobranzaPorIntegrante($credito);
        $saldos = collect($cobranza['integrantes'])->keyBy('id_cliente');
        $pendientes = $integrantes->filter(fn ($i) => (float) ($saldos[$i->id_cliente]['saldo_pendiente'] ?? 0) > 0.009);
        $disponible = (float) $pago->monto;
        $registros = [];
        foreach ($pendientes as $integrante) {
            if ($disponible <= 0.009) break;
            $saldo = (float) $saldos[$integrante->id_cliente]['saldo_pendiente'];
            $aplicado = min($saldo, (float) $integrante->valor_ficha, $disponible);
            if ($aplicado > 0.009) {
                $registros[] = ['pago_id' => $pago->id, 'id_cliente_integrante' => $integrante->id_cliente, 'monto' => $aplicado, 'created_at' => now(), 'updated_at' => now()];
                $disponible = round($disponible - $aplicado, 2);
            }
        }
        // Si fue un pago mayor a una ficha, el remanente continúa liquidando
        // en orden de saldo, sin exceder el adeudo de ningún integrante.
        foreach ($pendientes as $integrante) {
            if ($disponible <= 0.009) break;
            $yaAsignado = collect($registros)->where('id_cliente_integrante', $integrante->id_cliente)->sum('monto');
            $faltante = max(0, (float) $saldos[$integrante->id_cliente]['saldo_pendiente'] - $yaAsignado);
            $aplicado = min($faltante, $disponible);
            if ($aplicado > 0.009) { $registros[] = ['pago_id' => $pago->id, 'id_cliente_integrante' => $integrante->id_cliente, 'monto' => $aplicado, 'created_at' => now(), 'updated_at' => now()]; $disponible = round($disponible - $aplicado, 2); }
        }
        if ($registros) PagoGrupalAsignacion::insert($registros);
    }

    private function validarIntegrantes(Credito $credito, array $integrantes): Collection
    {
        if ($credito->tipo_credito !== 'Grupal' || ! $credito->id_grupo) {
            throw ValidationException::withMessages(['distribucion_integrantes' => 'La distribución sólo corresponde a créditos grupales.']);
        }
        if (count($integrantes) === 0) {
            throw ValidationException::withMessages(['distribucion_integrantes' => 'Registra a todos los integrantes del grupo.']);
        }

        $ids = collect($integrantes)->pluck('id_cliente')->map(fn ($id) => (string) $id);
        if ($ids->contains('') || $ids->count() !== $ids->unique()->count()) {
            throw ValidationException::withMessages(['distribucion_integrantes' => 'Los integrantes no pueden repetirse.']);
        }

        $clientes = Cliente::query()
            ->whereHas('grupos', fn ($query) => $query->where('grupos.id', $credito->id_grupo))
            ->whereIn('id_cliente', $ids)
            ->get()
            ->keyBy('id_cliente');
        $todosIds = $credito->grupo()->firstOrFail()->clientes()->pluck('clientes.id_cliente')->map(fn ($id) => (string) $id)->sort()->values();
        if ($clientes->count() !== $ids->count() || $ids->sort()->values()->all() !== $todosIds->all()) {
            throw ValidationException::withMessages(['distribucion_integrantes' => 'La distribución debe incluir exactamente a todos los integrantes actuales del grupo.']);
        }

        return $clientes;
    }

    private function reemplazar(Credito $credito, array $registros): void
    {
        DB::transaction(function () use ($credito, $registros) {
            $credito->distribucionesIntegrantes()->delete();
            $credito->distribucionesIntegrantes()->createMany($registros);
        });
    }

    private function registro(Credito $credito, Cliente $cliente, int $orden, int $capital, int $interes, int $total, int $ficha): array
    {
        return [
            'id_cliente' => $cliente->id_cliente,
            'nombre_cliente' => $cliente->nombre_completo,
            'curp' => $cliente->curp,
            'clave_elector' => $cliente->clave_elector,
            'telefono' => $cliente->telefono,
            'direccion' => $cliente->direccion,
            'capital' => $capital / 100,
            'interes' => $interes / 100,
            'total' => $total / 100,
            'valor_ficha' => $ficha / 100,
            'orden' => $orden,
            'folio_documental' => sprintf('%s-%s', $credito->num_prog, $cliente->id_cliente),
        ];
    }

    /** Largest-remainder allocation in cents; output always adds up exactly. */
    private function prorratear(Collection $capitales, int $total): Collection
    {
        $sumaCapital = $capitales->sum();
        if ($sumaCapital <= 0) {
            return $capitales->map(fn () => 0);
        }
        $base = $capitales->map(fn (int $capital) => intdiv($capital * $total, $sumaCapital));
        $faltantes = $total - $base->sum();
        $orden = $capitales->map(fn (int $capital, string $id) => [
            'id' => $id,
            'resto' => ($capital * $total) % $sumaCapital,
        ])->sortByDesc('resto')->sortBy('id')->values();
        // The second stable sort above makes ties deterministic by client ID;
        // restore remainder priority explicitly without losing the tie rule.
        $orden = $orden->sort(fn ($a, $b) => $b['resto'] <=> $a['resto'] ?: $a['id'] <=> $b['id'])->values();
        for ($i = 0; $i < $faltantes; $i++) {
            $id = $orden[$i]['id'];
            $base->put($id, $base->get($id) + 1);
        }
        return $base;
    }

    private function centavos(mixed $monto): int
    {
        return (int) round(((float) $monto) * 100);
    }
}
