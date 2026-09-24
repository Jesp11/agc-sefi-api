<?php

namespace App\Services;

use App\Models\AhorroPersonal;
use App\Models\AhorroSocio;
use App\Models\Credito;
use App\Models\ConfirmacionMovimiento;
use App\Models\GastoOperativo;
use App\Models\MovimientoCaja;
use App\Models\Pago;
use Carbon\Carbon;
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
            $categoria = $data['categoria'] ?? $this->inferirCategoria($motivo, $tipo);
            $this->validarSaldoInicial($data['fecha'], $categoria);

            $mov = MovimientoCaja::create([
                'fecha' => $data['fecha'],
                'id_asesor' => $data['id_asesor'] ?? null,
                'motivo' => $motivo,
                'tipo' => $tipo,
                'monto' => abs((float) $data['monto']),
                'categoria' => $categoria,
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

    public function registrarSaldoInicialDesdeCierre(string $mesCierre, float $monto): MovimientoCaja
    {
        $fechaInicial = Carbon::createFromFormat('Y-m', $mesCierre)->addMonth()->startOfMonth()->toDateString();

        return $this->registrar([
            'fecha' => $fechaInicial,
            'motivo' => "Saldo inicial por cierre de {$mesCierre}",
            'tipo' => 'Ingreso',
            'monto' => $monto,
            'categoria' => 'SaldoInicial',
            'referencia' => "cierre-mensual:{$mesCierre}",
        ]);
    }

    /** Guarda un egreso automático para revisión; aún no afecta la caja. */
    public function solicitarConfirmacionEgreso(array $data): ConfirmacionMovimiento
    {
        if (($data['tipo'] ?? 'Egreso') !== 'Egreso') {
            throw new \InvalidArgumentException('Sólo los egresos requieren confirmación.');
        }

        $referencia = $data['referencia'] ?? null;
        return DB::transaction(function () use ($data, $referencia) {
            $pendiente = $referencia
                ? ConfirmacionMovimiento::where('referencia', $referencia)->lockForUpdate()->first()
                : null;
            $campos = [
                'fecha' => $data['fecha'], 'id_asesor' => $data['id_asesor'] ?? null,
                'motivo' => $data['motivo'], 'monto' => abs((float) $data['monto']),
                'categoria' => $data['categoria'] ?? $this->inferirCategoria($data['motivo'], 'Egreso'),
                'cuenta' => $data['cuenta'] ?? null, 'num_prog' => $data['num_prog'] ?? null,
                'referencia' => $referencia, 'solicitado_por' => auth()->id(),
            ];
            if ($pendiente && $pendiente->estado === 'Pendiente') {
                $pendiente->update($campos);
                return $pendiente->fresh();
            }
            if ($pendiente) {
                return $pendiente;
            }
            return ConfirmacionMovimiento::create($campos);
        });
    }

    /** Confirma un egreso pendiente y lo registra finalmente en Flujo de Caja. */
    public function confirmarEgresoPendiente(ConfirmacionMovimiento $confirmacion): ConfirmacionMovimiento
    {
        return DB::transaction(function () use ($confirmacion) {
            $confirmacion = ConfirmacionMovimiento::lockForUpdate()->findOrFail($confirmacion->id);
            if ($confirmacion->estado !== 'Pendiente') {
                throw new \InvalidArgumentException('Este movimiento ya fue atendido.');
            }
            $movimiento = $this->registrar([
                'fecha' => $confirmacion->fecha->toDateString(), 'id_asesor' => $confirmacion->id_asesor,
                'motivo' => $confirmacion->motivo, 'tipo' => 'Egreso', 'monto' => $confirmacion->monto,
                'categoria' => $confirmacion->categoria, 'cuenta' => $confirmacion->cuenta,
                'num_prog' => $confirmacion->num_prog, 'referencia' => $confirmacion->referencia,
            ]);
            $requiereGestor = $confirmacion->requiereConfirmacionGestor();
            $confirmacion->update([
                'estado' => $requiereGestor ? 'EntregadoGestor' : 'Confirmado',
                'movimiento_caja_id' => $movimiento->id,
                'confirmado_por' => auth()->id(), 'confirmado_at' => now(),
            ]);
            return $confirmacion->fresh(['asesor', 'credito', 'movimientoCaja']);
        });
    }

    public function confirmarDesembolsoRenovacionPorGestor(ConfirmacionMovimiento $confirmacion): ConfirmacionMovimiento
    {
        return DB::transaction(function () use ($confirmacion) {
            $confirmacion = ConfirmacionMovimiento::lockForUpdate()->findOrFail($confirmacion->id);
            if ($confirmacion->estado !== 'EntregadoGestor' || ! $confirmacion->requiereConfirmacionGestor()) {
                throw new \InvalidArgumentException('El desembolso no está pendiente de confirmación por gestor.');
            }
            $confirmacion->update([
                'estado' => 'Confirmado',
                'entregado_gestor_por' => auth()->id(),
                'entregado_gestor_at' => now(),
            ]);
            Credito::where('num_prog', $confirmacion->num_prog)
                ->where('estado', 'PendienteDesembolso')
                ->update(['estado' => 'Activo']);

            return $confirmacion->fresh(['asesor', 'credito', 'movimientoCaja']);
        });
    }

    /** El gestor informa que no pudo entregar una renovación ya recibida. */
    public function cancelarDesembolsoRenovacionPorGestor(ConfirmacionMovimiento $confirmacion): ConfirmacionMovimiento
    {
        return DB::transaction(function () use ($confirmacion) {
            $confirmacion = ConfirmacionMovimiento::lockForUpdate()->findOrFail($confirmacion->id);
            if ($confirmacion->estado !== 'EntregadoGestor' || ! $confirmacion->requiereConfirmacionGestor()) {
                throw new \InvalidArgumentException('El desembolso no está disponible para cancelación por gestor.');
            }
            $confirmacion->update([
                'estado' => 'PendienteReintegro',
                'cancelado_gestor_por' => auth()->id(),
                'cancelado_gestor_at' => now(),
            ]);
            // Renovaciones creadas antes de este flujo podían estar activas
            // aun sin haberse entregado. Al cancelar la entrega se bloquean
            // para cobro hasta que se realice un nuevo desembolso.
            Credito::where('num_prog', $confirmacion->num_prog)
                ->where('estado', 'Activo')
                ->update(['estado' => 'PendienteDesembolso']);

            return $confirmacion->fresh(['asesor', 'credito', 'movimientoCaja']);
        });
    }

    /** Contabilidad confirma el regreso del efectivo y registra el ingreso compensatorio. */
    public function confirmarReintegroRenovacion(ConfirmacionMovimiento $confirmacion): ConfirmacionMovimiento
    {
        return DB::transaction(function () use ($confirmacion) {
            $confirmacion = ConfirmacionMovimiento::lockForUpdate()->findOrFail($confirmacion->id);
            if ($confirmacion->estado !== 'PendienteReintegro' || ! $confirmacion->requiereConfirmacionGestor()) {
                throw new \InvalidArgumentException('El reintegro no está pendiente de confirmación.');
            }

            $esRenovacion = mb_strtolower((string) $confirmacion->categoria) === 'renovacion';
            $concepto = $esRenovacion ? 'RENOVACIÓN CANCELADA' : 'DESEMBOLSO CANCELADO';
            $confirmacion->loadMissing(['credito.cliente', 'credito.grupo']);
            $beneficiario = $confirmacion->credito?->cliente?->nombre_completo
                ?? $confirmacion->credito?->grupo?->nombre_grupo
                ?? 'CLIENTE NO IDENTIFICADO';
            $movimiento = $this->registrar([
                'fecha' => now()->toDateString(),
                'id_asesor' => $confirmacion->id_asesor,
                'motivo' => "REINTEGRO DE {$concepto} #{$confirmacion->num_prog} — {$beneficiario}",
                'tipo' => 'Ingreso',
                'monto' => $confirmacion->monto,
                'categoria' => $esRenovacion ? 'ReintegroRenovacion' : 'ReintegroDesembolso',
                'cuenta' => 'Efectivo',
                'num_prog' => $confirmacion->num_prog,
                'referencia' => "REINTEGRO-RENOVACION-{$confirmacion->id}",
            ]);
            $confirmacion->update([
                'estado' => 'Reintegrado',
                'movimiento_reintegro_id' => $movimiento->id,
                'reintegrado_por' => auth()->id(),
                'reintegrado_at' => now(),
            ]);

            return $confirmacion->fresh(['asesor', 'credito', 'movimientoCaja', 'movimientoReintegro']);
        });
    }

    /** Crea una nueva solicitud de salida tras haber reintegrado una renovación no entregada. */
    public function reprogramarDesembolsoRenovacion(ConfirmacionMovimiento $confirmacion, ?string $fecha = null): ConfirmacionMovimiento
    {
        return DB::transaction(function () use ($confirmacion, $fecha) {
            $confirmacion = ConfirmacionMovimiento::lockForUpdate()->findOrFail($confirmacion->id);
            if ($confirmacion->estado !== 'Reintegrado' || ! $confirmacion->requiereConfirmacionGestor()) {
                throw new \InvalidArgumentException('Sólo se puede reprogramar un desembolso con efectivo reintegrado.');
            }

            $credito = Credito::find($confirmacion->num_prog);
            if (! $credito) {
                throw new \InvalidArgumentException('No se encontró el crédito del desembolso.');
            }
            // Compatibilidad con renovaciones canceladas antes de que existiera
            // el estado PendienteDesembolso.
            if ($credito->estado === 'Activo' && ! Pago::where('num_prog', $credito->num_prog)->exists()) {
                $credito->update(['estado' => 'PendienteDesembolso']);
            }
            if ($credito->fresh()->estado !== 'PendienteDesembolso') {
                throw new \InvalidArgumentException('El crédito ya no está pendiente de desembolso.');
            }
            if (ConfirmacionMovimiento::where('num_prog', $confirmacion->num_prog)
                ->whereIn('estado', ['Pendiente', 'EntregadoGestor'])
                ->exists()) {
                throw new \InvalidArgumentException('Ya existe un desembolso en proceso para este crédito.');
            }

            $intento = ConfirmacionMovimiento::where('num_prog', $confirmacion->num_prog)->count() + 1;
            $nuevoIntento = ConfirmacionMovimiento::create([
                'fecha' => $fecha ?? now()->toDateString(),
                'id_asesor' => $confirmacion->id_asesor,
                'motivo' => "REPROGRAMACIÓN — {$confirmacion->motivo}",
                'monto' => $confirmacion->monto,
                'categoria' => $confirmacion->categoria,
                'cuenta' => 'Efectivo',
                'num_prog' => $confirmacion->num_prog,
                'referencia' => "DESEMBOLSO-{$confirmacion->num_prog}-REINTENTO-{$intento}",
                'solicitado_por' => auth()->id(),
            ]);
            $confirmacion->update(['estado' => 'Reprogramado']);

            return $nuevoIntento->fresh(['asesor', 'credito']);
        });
    }

    /** Cierra el flujo después del reintegro cuando el desembolso ya no se realizará. */
    public function cancelarDefinitivamenteDesembolsoReintegrado(ConfirmacionMovimiento $confirmacion): ConfirmacionMovimiento
    {
        return DB::transaction(function () use ($confirmacion) {
            $confirmacion = ConfirmacionMovimiento::lockForUpdate()->findOrFail($confirmacion->id);
            if ($confirmacion->estado !== 'Reintegrado' || ! $confirmacion->requiereConfirmacionGestor()) {
                throw new \InvalidArgumentException('Sólo se puede cancelar definitivamente un desembolso ya reintegrado.');
            }
            if (ConfirmacionMovimiento::where('num_prog', $confirmacion->num_prog)
                ->where('id', '!=', $confirmacion->id)
                ->whereIn('estado', ['Pendiente', 'EntregadoGestor'])
                ->exists()) {
                throw new \InvalidArgumentException('Ya existe otro desembolso en proceso para este crédito.');
            }

            // Se conserva la auditoría original de entrega y reintegro; el
            // updated_at deja constancia del cierre administrativo.
            $confirmacion->update(['estado' => 'Cancelado']);

            return $confirmacion->fresh(['asesor', 'credito', 'movimientoCaja', 'movimientoReintegro']);
        });
    }

    /** Edita un movimiento manual o importado y recalcula el saldo posterior. */
    public function actualizar(MovimientoCaja $movimiento, array $data): MovimientoCaja
    {
        if ($movimiento->pago_id || str_starts_with((string) $movimiento->referencia, 'DESEMBOLSO-')) {
            throw new \InvalidArgumentException('Este movimiento se genera desde un pago o desembolso. Corrige el registro de origen para conservar la caja sincronizada.');
        }

        if (str_starts_with((string) $movimiento->referencia, 'GASTO-')) {
            $movimiento->update(['id_asesor' => $data['id_asesor'] ?? null]);

            return $movimiento->fresh(['asesor', 'registradoPor']);
        }

        return DB::transaction(function () use ($movimiento, $data) {
            $fechaAnterior = $movimiento->fecha->format('Y-m-d');
            $motivo = $data['motivo'];
            $tipo = $data['tipo'];
            $categoria = $data['categoria'] ?? $this->inferirCategoria($motivo, $tipo);
            $this->validarSaldoInicial($data['fecha'], $categoria, $movimiento->id);
            $movimiento->update([
                'fecha' => $data['fecha'],
                'id_asesor' => $data['id_asesor'] ?? null,
                'motivo' => $motivo,
                'tipo' => $tipo,
                'monto' => abs((float) $data['monto']),
                'categoria' => $categoria,
                'cuenta' => $data['cuenta'] ?? null,
                'num_prog' => $data['num_prog'] ?? $movimiento->num_prog,
            ]);

            $this->recalcularSaldosDesde(min($fechaAnterior, $data['fecha']));

            return $movimiento->fresh(['asesor', 'credito.cliente', 'credito.grupo']);
        });
    }

    /** Corrige únicamente la fecha contable de un desembolso automático confirmado. */
    public function corregirFechaDesembolso(MovimientoCaja $movimiento, string $fecha): MovimientoCaja
    {
        if (! str_starts_with((string) $movimiento->referencia, 'DESEMBOLSO-')) {
            throw new \InvalidArgumentException('Solo se puede corregir por esta vía la fecha de un desembolso automático.');
        }

        return DB::transaction(function () use ($movimiento, $fecha) {
            $movimiento = MovimientoCaja::whereKey($movimiento->id)->lockForUpdate()->firstOrFail();
            $fechaAnterior = $movimiento->fecha->toDateString();
            $fechaNueva = Carbon::parse($fecha)->toDateString();

            $movimiento->update(['fecha' => $fechaNueva]);

            ConfirmacionMovimiento::query()
                ->where(function ($query) use ($movimiento) {
                    $query->where('movimiento_caja_id', $movimiento->id)
                        ->orWhere('referencia', $movimiento->referencia);
                })
                ->update(['fecha' => $fechaNueva]);

            $this->recalcularSaldosDesde(min($fechaAnterior, $fechaNueva));

            return $movimiento->fresh(['asesor', 'credito.cliente', 'credito.grupo']);
        });
    }

    /** Elimina un movimiento manual o importado y recalcula los saldos posteriores. */
    public function eliminar(MovimientoCaja $movimiento): void
    {
        $esRendimientoRegistradoComoGasto = str_starts_with((string) $movimiento->referencia, 'GASTO-')
            && str_starts_with(mb_strtoupper((string) $movimiento->categoria), 'RENDIMIENTO');

        if ($movimiento->pago_id || str_starts_with((string) $movimiento->referencia, 'DESEMBOLSO-') || (str_starts_with((string) $movimiento->referencia, 'GASTO-') && ! $esRendimientoRegistradoComoGasto)) {
            throw new \InvalidArgumentException('Este movimiento se genera automáticamente. Elimínalo desde el pago, gasto o desembolso de origen.');
        }

        DB::transaction(function () use ($movimiento) {
            $fecha = $movimiento->fecha->format('Y-m-d');

            if (str_starts_with((string) $movimiento->referencia, 'GASTO-')) {
                GastoOperativo::whereKey((int) substr((string) $movimiento->referencia, strlen('GASTO-')))->delete();
                \App\Models\MovimientoCapital::where('referencia', $movimiento->referencia)->delete();
            }

            $movimiento->delete();
            $this->recalcularSaldosDesde($fecha);
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

    /**
     * Crea o actualiza el ingreso de caja asociado a un abono.
     *
     * Los pagos anteriores a la integración con Flujo de Caja no siempre
     * tienen movimiento. Al sincronizarlos se conserva una sola referencia
     * por pago y se recalculan los saldos desde la fecha más antigua afectada.
     */
    public function sincronizarDesdePago(Pago $pago, Credito $credito): ?MovimientoCaja
    {
        if ($pago->tipo !== 'Abono') {
            return null;
        }

        $movimiento = MovimientoCaja::where('pago_id', $pago->id)->first();
        if (! $movimiento) {
            return $this->registrarDesdePago($pago, $credito);
        }

        return DB::transaction(function () use ($movimiento, $pago, $credito) {
            $fechaAnterior = $movimiento->fecha->format('Y-m-d');
            $clienteNombre = $credito->cliente?->nombre_completo
                ?? $credito->grupo?->nombre_grupo
                ?? 'Crédito #'.$credito->num_prog;

            $movimiento->update([
                'fecha' => $pago->fecha->format('Y-m-d'),
                'id_asesor' => $credito->id_asesor,
                'motivo' => "COBRO CRÉDITO #{$credito->num_prog} — {$clienteNombre}",
                'tipo' => 'Ingreso',
                'monto' => abs((float) $pago->monto),
                'categoria' => 'CobroCartera',
                'cuenta' => $this->mapMetodoPagoCuenta($pago->metodo_pago),
                'num_prog' => $credito->num_prog,
                'pago_id' => $pago->id,
                'referencia' => "PAGO-{$pago->id}",
            ]);

            $this->recalcularSaldosDesde(min($fechaAnterior, $pago->fecha->format('Y-m-d')));

            return $movimiento->fresh(['asesor', 'credito.cliente', 'credito.grupo']);
        });
    }

    /**
     * Registra en caja únicamente el efectivo que el administrador confirmó
     * haber recibido del gestor. Un pago capturado todavía no es un ingreso
     * de la empresa mientras permanezca en poder del gestor.
     */
    public function sincronizarCobroRecibido(
        Pago $pago,
        Credito $credito,
        float $montoRecibido,
        string $fechaRecepcion,
    ): ?MovimientoCaja {
        if ($pago->tipo !== 'Abono') {
            return null;
        }

        $movimiento = MovimientoCaja::where('pago_id', $pago->id)->first();
        $montoRecibido = round(max(0, min(abs($montoRecibido), abs((float) $pago->monto))), 2);

        if ($montoRecibido < 0.01) {
            if (! $movimiento) {
                return null;
            }

            return DB::transaction(function () use ($movimiento) {
                $fechaAnterior = $movimiento->fecha->format('Y-m-d');
                $movimiento->delete();
                $this->recalcularSaldosDesde($fechaAnterior);

                return null;
            });
        }

        $clienteNombre = $credito->cliente?->nombre_completo
            ?? $credito->grupo?->nombre_grupo
            ?? 'Crédito #'.$credito->num_prog;
        $datos = [
            'fecha' => $fechaRecepcion,
            'id_asesor' => $credito->id_asesor,
            'motivo' => "RECEPCIÓN COBRO CRÉDITO #{$credito->num_prog} — {$clienteNombre}",
            'tipo' => 'Ingreso',
            'monto' => $montoRecibido,
            'categoria' => $credito->estado === 'EnMora' ? 'RecuperacionMora' : 'CobroCartera',
            'cuenta' => $this->mapMetodoPagoCuenta($pago->metodo_pago),
            'num_prog' => $credito->num_prog,
            'pago_id' => $pago->id,
            'referencia' => "RECEPCION-PAGO-{$pago->id}",
        ];

        if (! $movimiento) {
            return $this->registrar($datos);
        }

        return DB::transaction(function () use ($movimiento, $datos) {
            $fechaAnterior = $movimiento->fecha->format('Y-m-d');
            $movimiento->update($datos);
            $this->recalcularSaldosDesde(min($fechaAnterior, $datos['fecha']));

            return $movimiento->fresh(['asesor', 'credito.cliente', 'credito.grupo']);
        });
    }

    public function registrarDesdeGasto(GastoOperativo $gasto): ?MovimientoCaja
    {
        if (MovimientoCaja::where('referencia', "GASTO-{$gasto->id}")->exists()) {
            return MovimientoCaja::where('referencia', "GASTO-{$gasto->id}")->first();
        }

        $this->solicitarConfirmacionEgreso([
            'fecha' => $gasto->fecha->format('Y-m-d'),
            'id_asesor' => $gasto->registradoPor?->id_asesor,
            'motivo' => $gasto->concepto,
            'tipo' => 'Egreso',
            'monto' => $gasto->monto,
            'categoria' => $gasto->categoria ?: 'GastoOperativo',
            'cuenta' => $gasto->cuenta,
            'referencia' => "GASTO-{$gasto->id}",
        ]);
        return null;
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

        $this->solicitarConfirmacionEgreso([
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
        return null;
    }

    /**
     * Sincroniza el egreso generado por un crédito cuando cambia el monto entregado.
     * La referencia única evita que una edición cree desembolsos duplicados.
     */
    public function sincronizarDesembolso(
        Credito $credito,
        ?float $monto = null,
        ?string $motivo = null,
        ?string $categoria = null,
    ): ?MovimientoCaja
    {
        $monto = $monto ?? (float) $credito->monto_otorgado;
        $referencia = "DESEMBOLSO-{$credito->num_prog}";
        $movimiento = MovimientoCaja::where('referencia', $referencia)->first();

        if (! $movimiento) {
            return $this->registrarDesdeDesembolso($credito, $monto, $motivo, $categoria);
        }

        return DB::transaction(function () use ($movimiento, $monto, $motivo, $categoria) {
            $cambios = [
                'monto' => abs($monto),
            ];
            if ($motivo !== null) {
                $cambios['motivo'] = $motivo;
            }
            if ($categoria !== null) {
                $cambios['categoria'] = $categoria;
            }
            $movimiento->update($cambios);

            $this->recalcularSaldosDesde($movimiento->fecha->format('Y-m-d'));

            return $movimiento->fresh(['asesor', 'credito.cliente', 'credito.grupo']);
        });
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
            ->orderByRaw("CASE WHEN categoria = 'SaldoInicial' THEN 0 ELSE 1 END")
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

    /** Calcula el cierre previo usando como base el saldo inicial válido más reciente. */
    private function saldoAntesDe(string $fechaLimite): float
    {
        $saldoInicial = MovimientoCaja::query()
            ->whereDate('fecha', '<', $fechaLimite)
            ->where('categoria', 'SaldoInicial')
            ->whereDay('fecha', 1)
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->first();

        if (! $saldoInicial) {
            return (float) (MovimientoCaja::query()
                ->whereDate('fecha', '<', $fechaLimite)
                ->whereNotNull('saldo_resultante')
                ->orderByDesc('fecha')
                ->orderByDesc('id')
                ->value('saldo_resultante') ?? 0);
        }

        $movimientos = MovimientoCaja::query()
            ->whereDate('fecha', '>=', $saldoInicial->fecha->toDateString())
            ->whereDate('fecha', '<', $fechaLimite)
            ->where(function ($query) {
                $query->whereNull('categoria')->orWhere('categoria', '!=', 'SaldoInicial');
            })
            ->get();

        $base = $saldoInicial->tipo === 'Ingreso'
            ? (float) $saldoInicial->monto
            : -(float) $saldoInicial->monto;
        $ingresos = (float) $movimientos->where('tipo', 'Ingreso')->sum('monto');
        $egresos = (float) $movimientos->where('tipo', 'Egreso')->sum('monto');

        return round($base + $ingresos - $egresos, 2);
    }

    /**
     * El saldo inicial es la base del mes, no un movimiento ordinario. Sólo
     * puede existir uno, fechado el día 1, para evitar reinicios posteriores
     * del saldo acumulado.
     */
    private function validarSaldoInicial(string $fecha, ?string $categoria, ?int $movimientoIdExcluido = null): void
    {
        if ($categoria !== 'SaldoInicial') {
            return;
        }

        $fechaSaldoInicial = Carbon::parse($fecha);
        if (! $fechaSaldoInicial->isSameDay($fechaSaldoInicial->copy()->startOfMonth())) {
            throw new \InvalidArgumentException('El saldo inicial debe registrarse el primer día del mes.');
        }

        $existe = MovimientoCaja::query()
            ->whereYear('fecha', $fechaSaldoInicial->year)
            ->whereMonth('fecha', $fechaSaldoInicial->month)
            ->where('categoria', 'SaldoInicial')
            ->when($movimientoIdExcluido, fn ($query) => $query->where('id', '!=', $movimientoIdExcluido))
            ->exists();

        if ($existe) {
            throw new \InvalidArgumentException('Ya existe un saldo inicial para este mes. Edita ese registro para corregir la base.');
        }
    }

    public function listar(?int $mes = null, ?int $anio = null, ?string $tipo = null, ?string $fecha = null)
    {
        $query = MovimientoCaja::with(['asesor', 'registradoPor', 'credito.cliente', 'credito.grupo'])
            ->orderByDesc('fecha')
            ->orderByDesc('id');

        if ($fecha) {
            $query->whereDate('fecha', $fecha);
        } elseif ($anio) {
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

    public function resumen(?int $mes = null, ?int $anio = null, ?string $fecha = null): array
    {
        $anio = $anio ?? (int) now()->year;
        $mes = $mes ?? (int) now()->month;
        $fechaConsulta = $fecha ? Carbon::parse($fecha)->toDateString() : null;

        $movimientosMes = MovimientoCaja::query()
            ->when($fechaConsulta, fn ($query) => $query->whereDate('fecha', $fechaConsulta), fn ($query) => $query->whereYear('fecha', $anio)->whereMonth('fecha', $mes))
            ->get();

        // Un saldo inicial explícito sólo puede establecer la base el día 1.
        // En cualquier otro día se arrastra exclusivamente el cierre anterior.
        $saldoInicialRow = $movimientosMes->first(
            fn ($movimiento) => $movimiento->categoria === 'SaldoInicial' && $movimiento->fecha->day === 1
        );

        $inicioPeriodo = $fechaConsulta ?? Carbon::create($anio, $mes, 1)->toDateString();
        $saldoAnterior = $this->saldoAntesDe($inicioPeriodo);

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

        $ultimoDelPeriodo = MovimientoCaja::query()
            ->when($fechaConsulta,
                fn ($query) => $query->whereDate('fecha', $fechaConsulta),
                fn ($query) => $query->whereYear('fecha', $anio)->whereMonth('fecha', $mes)
            )
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

        $distribucionCategoriasEgresos = $movimientosMes
            // Desembolsos y renovaciones son salidas de crédito, no gastos
            // operativos; se omiten del pastel de gastos del cierre mensual.
            ->filter(fn ($m) => $m->tipo === 'Egreso'
                && ! in_array($m->categoria, ['SaldoInicial', 'Desembolso', 'Renovacion'], true))
            ->groupBy(fn ($m) => trim((string) ($m->categoria ?: 'Sin categoría')))
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
        $gastosOperativos = GastoOperativo::whereYear('fecha', $anio)
            ->whereMonth('fecha', $mes)
            ->sum('monto');
        $rendimientosInversionistas = $movimientosMes
            ->where('tipo', 'Egreso')
            ->where('categoria', 'Rendimiento')
            ->sum('monto');
        $nomina = $movimientosMes
            ->where('tipo', 'Egreso')
            ->where('categoria', 'Nomina')
            ->sum('monto');

        return [
            'anio' => $anio,
            'mes' => $mes,
            'saldo_inicial_mes' => round($saldoInicialMes, 2),
            'total_ingresos' => round((float) $ingresos, 2),
            'total_egresos' => round((float) $egresos, 2),
            'flujo_neto' => round((float) $ingresos - (float) $egresos, 2),
            'saldo_anterior' => round((float) ($saldoAnterior ?? 0), 2),
            'saldo_actual' => round((float) ($ultimoDelPeriodo?->saldo_resultante ?? $disponible), 2),
            'disponible' => $disponible,
            'gastos_operativos' => round((float) $gastosOperativos, 2),
            'rendimientos_inversionistas' => round((float) $rendimientosInversionistas, 2),
            'nomina' => round((float) $nomina, 2),
            'distribucion_cuentas' => [
                'ingresos' => $distribucionIngresos,
                'egresos' => $distribucionEgresos,
            ],
            'distribucion_categorias' => [
                'egresos' => $distribucionCategoriasEgresos,
            ],
            'cartera_individual' => round((float) $carteraIndividual, 2),
            'cartera_grupal' => round((float) $carteraGrupal, 2),
            'mora' => round((float) $mora, 2),
            'ahorro_personal' => round((float) $ahorroPersonal, 2),
            'ahorro_grupal' => round((float) $ahorroGrupal, 2),
        ];
    }
}
