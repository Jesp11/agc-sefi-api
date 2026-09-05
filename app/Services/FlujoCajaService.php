<?php

namespace App\Services;

use App\Models\AhorroPersonal;
use App\Models\AhorroSocio;
use App\Models\Credito;
use App\Models\GastoOperativo;
use App\Models\MovimientoCaja;
use App\Models\Pago;
use Illuminate\Support\Facades\DB;

class FlujoCajaService
{
    public const CUENTAS = [
        'Efectivo', 'Spin', 'Bancomer', 'Banorte', 'Banamex', 'BBVA', 'Nue', 'Otro',
    ];

    public function inferirCategoria(string $motivo, string $tipo): string
    {
        $m = mb_strtoupper($motivo);

        if (str_contains($m, 'SALDO MES')) {
            return 'SaldoInicial';
        }
        if (str_contains($m, 'PAGO') && preg_match('/\d+\/\d+/', $m)) {
            return 'CobroCartera';
        }
        if (str_contains($m, 'RECUPERACION MORA') || str_contains($m, 'RECUPERACIÓN MORA')) {
            return 'RecuperacionMora';
        }
        if (str_contains($m, 'NOMINA') || str_contains($m, 'NÓMINA')) {
            return 'Nomina';
        }
        if (str_contains($m, 'INSUMOS') && str_contains($m, 'SOCIOS')) {
            return 'InsumosSocios';
        }
        if (str_contains($m, 'TELEFON') || str_contains($m, 'TELÉFON')) {
            return 'ServicioTelefono';
        }
        if (str_contains($m, 'RENDIMIENTO')) {
            return 'Rendimiento';
        }
        if (str_contains($m, 'RENOVACION') || str_contains($m, 'RENOVACIÓN')) {
            return 'Renovacion';
        }
        if (str_contains($m, 'DESEMBOLSO')) {
            return 'Desembolso';
        }

        return $tipo === 'Ingreso' ? 'OtroIngreso' : 'OtroEgreso';
    }

    public function registrar(array $data): MovimientoCaja
    {
        return DB::transaction(function () use ($data) {
            $tipo = $data['tipo'];
            $motivo = $data['motivo'];
            $mov = MovimientoCaja::create([
                'fecha' => $data['fecha'],
                'id_asesor' => $data['id_asesor'] ?? null,
                'motivo' => $motivo,
                'tipo' => $tipo,
                'monto' => abs((float) $data['monto']),
                'categoria' => $data['categoria'] ?? $this->inferirCategoria($motivo, $tipo),
                'cuenta' => $data['cuenta'] ?? null,
                'num_prog' => $data['num_prog'] ?? null,
                'pago_id' => $data['pago_id'] ?? null,
                'referencia' => $data['referencia'] ?? null,
                'registrado_por' => auth()->id(),
            ]);

            $this->recalcularSaldosDesde($mov->fecha);

            return $mov->fresh(['asesor', 'credito.cliente', 'credito.grupo']);
        });
    }

    /** Edita un movimiento manual o importado y recalcula el saldo posterior. */
    public function actualizar(MovimientoCaja $movimiento, array $data): MovimientoCaja
    {
        if ($movimiento->pago_id || str_starts_with((string) $movimiento->referencia, 'GASTO-') || str_starts_with((string) $movimiento->referencia, 'DESEMBOLSO-')) {
            throw new \InvalidArgumentException('Este movimiento se genera desde un pago, gasto o desembolso. Corrige el registro de origen para conservar la caja sincronizada.');
        }

        return DB::transaction(function () use ($movimiento, $data) {
            $fechaAnterior = $movimiento->fecha->format('Y-m-d');
            $motivo = $data['motivo'];
            $tipo = $data['tipo'];
            $movimiento->update([
                'fecha' => $data['fecha'],
                'id_asesor' => $data['id_asesor'] ?? null,
                'motivo' => $motivo,
                'tipo' => $tipo,
                'monto' => abs((float) $data['monto']),
                'categoria' => $data['categoria'] ?? $this->inferirCategoria($motivo, $tipo),
                'cuenta' => $data['cuenta'] ?? null,
                'num_prog' => $data['num_prog'] ?? $movimiento->num_prog,
            ]);

            $this->recalcularSaldosDesde(min($fechaAnterior, $data['fecha']));

            return $movimiento->fresh(['asesor', 'credito.cliente', 'credito.grupo']);
        });
    }

    public function registrarDesdePago(Pago $pago, Credito $credito): ?MovimientoCaja
    {
        if ($pago->tipo !== 'Abono') {
            return null;
        }

        if (MovimientoCaja::where('pago_id', $pago->id)->exists()) {
            return null;
        }

        $clienteNombre = $credito->cliente?->nombre_completo
            ?? $credito->grupo?->nombre_grupo
            ?? 'Crédito #'.$credito->num_prog;

        return $this->registrar([
            'fecha' => $pago->fecha->format('Y-m-d'),
            'id_asesor' => $credito->id_asesor,
            'motivo' => "COBRO CRÉDITO #{$credito->num_prog} — {$clienteNombre}",
            'tipo' => 'Ingreso',
            'monto' => $pago->monto,
            'categoria' => 'CobroCartera',
            'cuenta' => $this->mapMetodoPagoCuenta($pago->metodo_pago),
            'num_prog' => $credito->num_prog,
            'pago_id' => $pago->id,
            'referencia' => "PAGO-{$pago->id}",
        ]);
    }

    public function registrarDesdeGasto(GastoOperativo $gasto): MovimientoCaja
    {
        if (MovimientoCaja::where('referencia', "GASTO-{$gasto->id}")->exists()) {
            return MovimientoCaja::where('referencia', "GASTO-{$gasto->id}")->first();
        }

        return $this->registrar([
            'fecha' => $gasto->fecha->format('Y-m-d'),
            'motivo' => $gasto->concepto,
            'tipo' => 'Egreso',
            'monto' => $gasto->monto,
            'categoria' => $gasto->categoria ?: 'GastoOperativo',
            'cuenta' => $gasto->cuenta,
            'referencia' => "GASTO-{$gasto->id}",
        ]);
    }

    /**
     * Egreso por desembolso de efectivo (crédito nuevo o refinanciamiento).
     */
    public function registrarDesdeDesembolso(Credito $credito, float $monto, ?string $motivo = null, ?string $categoria = null): ?MovimientoCaja
    {
        $monto = abs($monto);
        if ($monto < 0.01) {
            return null;
        }

        $referencia = "DESEMBOLSO-{$credito->num_prog}";
        if (MovimientoCaja::where('referencia', $referencia)->exists()) {
            return MovimientoCaja::where('referencia', $referencia)->first();
        }

        $credito->loadMissing(['cliente', 'grupo']);
        $beneficiario = $credito->cliente?->nombre_completo
            ?? $credito->grupo?->nombre_grupo
            ?? "Crédito #{$credito->num_prog}";

        $fecha = $credito->fecha_otorgacion
            ? (is_string($credito->fecha_otorgacion)
                ? $credito->fecha_otorgacion
                : $credito->fecha_otorgacion->format('Y-m-d'))
            : now()->toDateString();

        return $this->registrar([
            'fecha' => $fecha,
            'id_asesor' => $credito->id_asesor,
            'motivo' => $motivo ?? "DESEMBOLSO CRÉDITO #{$credito->num_prog} — {$beneficiario}",
            'tipo' => 'Egreso',
            'monto' => $monto,
            'categoria' => $categoria ?? 'Desembolso',
            'cuenta' => 'Efectivo',
            'num_prog' => $credito->num_prog,
            'referencia' => $referencia,
        ]);
    }

    private function mapMetodoPagoCuenta(string $metodo): string
    {
        return match ($metodo) {
            'Transferencia' => 'Bancomer',
            default => 'Efectivo',
        };
    }

    public function recalcularSaldosDesde(string $fechaInicio): void
    {
        // Los saldos de los meses históricos provienen de los libros de caja.
        // Partir de cero y volver a sumar todo el histórico puede invalidar ese
        // cierre al capturar un movimiento nuevo. Se usa el último saldo ya
        // confirmado anterior a la fecha afectada como saldo de apertura.
        $saldo = (float) (MovimientoCaja::where('fecha', '<', $fechaInicio)
            ->whereNotNull('saldo_resultante')
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->value('saldo_resultante') ?? 0);

        $pendientes = MovimientoCaja::where('fecha', '>=', $fechaInicio)
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();

        foreach ($pendientes as $mov) {
            $saldo = $this->aplicarMovimiento($saldo, $mov);
            if ((float) $mov->saldo_resultante !== round($saldo, 2)) {
                $mov->update(['saldo_resultante' => round($saldo, 2)]);
            }
        }
    }

    private function aplicarMovimiento(float $saldo, MovimientoCaja $mov): float
    {
        if ($mov->categoria === 'SaldoInicial') {
            return $mov->tipo === 'Ingreso' ? (float) $mov->monto : -(float) $mov->monto;
        }

        if ($mov->tipo === 'Ingreso') {
            return $saldo + (float) $mov->monto;
        }

        return $saldo - (float) $mov->monto;
    }

    public function listar(?int $mes = null, ?int $anio = null, ?string $tipo = null)
    {
        $query = MovimientoCaja::with(['asesor', 'credito.cliente', 'credito.grupo'])
            ->orderByDesc('fecha')
            ->orderByDesc('id');

        if ($anio) {
            $query->whereYear('fecha', $anio);
        }
        if ($mes) {
            $query->whereMonth('fecha', $mes);
        }
        if ($tipo && in_array($tipo, ['Ingreso', 'Egreso'], true)) {
            $query->where('tipo', $tipo);
        }

        return $query;
    }

    public function resumen(?int $mes = null, ?int $anio = null): array
    {
        $anio = $anio ?? (int) now()->year;
        $mes = $mes ?? (int) now()->month;

        $movimientosMes = MovimientoCaja::whereYear('fecha', $anio)
            ->whereMonth('fecha', $mes)
            ->get();

        $saldoInicialRow = $movimientosMes->where('categoria', 'SaldoInicial')->first();

        $saldoAnterior = MovimientoCaja::where(function ($q) use ($anio, $mes) {
            $q->whereYear('fecha', '<', $anio)
                ->orWhere(function ($q2) use ($anio, $mes) {
                    $q2->whereYear('fecha', $anio)->whereMonth('fecha', '<', $mes);
                });
        })
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->value('saldo_resultante');

        if ($saldoInicialRow) {
            $saldoInicialMes = $saldoInicialRow->tipo === 'Ingreso'
                ? (float) $saldoInicialRow->monto
                : -(float) $saldoInicialRow->monto;
        } else {
            $saldoInicialMes = (float) ($saldoAnterior ?? 0);
        }

        $ingresos = $movimientosMes
            ->where('tipo', 'Ingreso')
            ->where('categoria', '!=', 'SaldoInicial')
            ->sum('monto');

        $egresos = $movimientosMes
            ->where('tipo', 'Egreso')
            ->where('categoria', '!=', 'SaldoInicial')
            ->sum('monto');

        $ultimoDelMes = MovimientoCaja::whereYear('fecha', $anio)
            ->whereMonth('fecha', $mes)
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->first();

        $disponible = round($saldoInicialMes + (float) $ingresos - (float) $egresos, 2);

        $distribucionIngresos = $movimientosMes
            ->filter(fn ($m) => $m->cuenta && $m->tipo === 'Ingreso' && $m->categoria !== 'SaldoInicial')
            ->groupBy('cuenta')
            ->map(fn ($items) => round((float) $items->sum('monto'), 2));

        $distribucionEgresos = $movimientosMes
            ->filter(fn ($m) => $m->cuenta && $m->tipo === 'Egreso' && $m->categoria !== 'SaldoInicial')
            ->groupBy('cuenta')
            ->map(fn ($items) => round((float) $items->sum('monto'), 2));

        $carteraIndividual = Credito::where('tipo_credito', 'Individual')
            ->where('estado', 'Activo')
            ->sum('saldo_pendiente');

        $carteraGrupal = Credito::where('tipo_credito', 'Grupal')
            ->where('estado', 'Activo')
            ->sum('saldo_pendiente');

        $mora = Credito::where('estado', 'EnMora')->sum('saldo_pendiente');
        $ahorroPersonal = AhorroPersonal::sum('saldo');
        $ahorroGrupal = AhorroSocio::sum('saldo');
        $gastosOperativos = round((float) $movimientosMes
            ->where('tipo', 'Egreso')
            ->where('categoria', 'GastoOperativo')
            ->sum('monto'), 2);

        return [
            'anio' => $anio,
            'mes' => $mes,
            'saldo_inicial_mes' => round($saldoInicialMes, 2),
            'total_ingresos' => round((float) $ingresos, 2),
            'total_egresos' => round((float) $egresos, 2),
            'flujo_neto' => round((float) $ingresos - (float) $egresos, 2),
            'saldo_anterior' => round((float) ($saldoAnterior ?? 0), 2),
            'saldo_actual' => round((float) ($ultimoDelMes?->saldo_resultante ?? $disponible), 2),
            'disponible' => $disponible,
            'gastos_operativos' => $gastosOperativos,
            'distribucion_cuentas' => [
                'ingresos' => $distribucionIngresos,
                'egresos' => $distribucionEgresos,
            ],
            'cartera_individual' => round((float) $carteraIndividual, 2),
            'cartera_grupal' => round((float) $carteraGrupal, 2),
            'mora' => round((float) $mora, 2),
            'ahorro_personal' => round((float) $ahorroPersonal, 2),
            'ahorro_grupal' => round((float) $ahorroGrupal, 2),
        ];
    }
}
