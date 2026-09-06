<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Credito;
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
