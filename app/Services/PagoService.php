<?php

namespace App\Services;

use App\Models\AhorroPersonal;
use App\Models\AhorroPersonalMovimiento;
use App\Models\Credito;
use App\Models\MovimientoCaja;
use App\Models\Pago;
use App\Support\RoleHelper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PagoService
{
    public function __construct(
        private MoraCalculationService $moraService,
        private DistribucionCreditoGrupalService $distribucionGrupalService,
    ) {}

    /**
     * Registra un abono y, opcionalmente, una multa en la misma operación.
     *
     * @return array{pago: Pago, pagos: list<Pago>, multa: Pago|null}
     */
    public function registrar(Credito $credito, array $data): array
    {
        if ($credito->estado === 'PendienteDesembolso') {
            throw new \InvalidArgumentException('Este crédito está pendiente de desembolso y aún no puede recibir pagos.');
        }

        return DB::transaction(function () use ($credito, $data) {
            $hora = $data['hora'] ?? now()->format('H:i:s');
            $base = [
                'num_prog' => $credito->num_prog,
                'fecha' => $data['fecha'],
                'hora' => $hora,
                'metodo_pago' => $data['metodo_pago'] ?? 'Efectivo',
                'notas' => $data['notas'] ?? null,
                'referencia_importacion' => $data['referencia_importacion'] ?? null,
                'registrado_por' => Auth::id(),
            ];
            if (!empty($data['id_cliente_integrante'])) {
                $base['id_cliente_integrante'] = (string) $data['id_cliente_integrante'];
            }

            $tipo = $data['tipo'] ?? 'Abono';
            $montoMulta = (float) ($data['monto_multa'] ?? 0);
            $ahorroPersonalMonto = abs((float) ($data['ahorro_personal_monto'] ?? 0));
            $pagos = [];

            if ($ahorroPersonalMonto > 0 && RoleHelper::isFieldLike(Auth::user()?->role?->nombre)) {
                throw new \InvalidArgumentException('El gestor de cobranza no puede registrar ahorro personal en abonos.');
            }

            // Compatibilidad: registro solo de multa (sin abono aparte).
            if ($tipo === 'Multa' && $montoMulta <= 0) {
                $multa = Pago::create([
                    ...$base,
                    'monto' => $data['monto'],
                    'ahorro_personal_monto' => 0,
                    'tipo' => 'Multa',
                ]);
                $pagos[] = $multa;

                $this->syncCredito($credito);

                return [
                    'pago' => $multa->load('registradoPor'),
                    'pagos' => $pagos,
                    'multa' => $multa,
                ];
            }

            $this->validarMontoIntegrante(
                $credito,
                $data['id_cliente_integrante'] ?? null,
                (float) $data['monto'],
            );

            $abono = Pago::create([
                ...$base,
                'monto' => $data['monto'],
                'ahorro_personal_monto' => $ahorroPersonalMonto,
                'tipo' => 'Abono',
            ]);
            $pagos[] = $abono;
            $this->distribucionGrupalService->asignarPagoAutomaticamente($credito, $abono);
            $this->registrarAhorroPersonalDesdePago($credito, $abono, $ahorroPersonalMonto);

            $multa = null;
            if ($montoMulta > 0) {
                $multa = Pago::create([
                    ...$base,
                    'monto' => $montoMulta,
                    'tipo' => 'Multa',
                ]);
                $pagos[] = $multa;
            }

            $this->syncCredito($credito);

            return [
                'pago' => $abono->load('registradoPor'),
                'pagos' => collect($pagos)->map->load('registradoPor')->all(),
                'multa' => $multa?->load('registradoPor'),
            ];
        });
    }

    private function syncCredito(Credito $credito): void
    {
        $credito->refresh();
        $credito->load('pagos', 'cliente', 'grupo');
        $this->moraService->syncCreditoState($credito);
    }

    /** Impide que un integrante pague más de su propio documento o del saldo grupal. */
    private function validarMontoIntegrante(Credito $credito, ?string $idCliente, float $monto, ?int $pagoExcluido = null): void
    {
        if (empty($idCliente)) {
            return;
        }
        if ($credito->tipo_credito !== 'Grupal') {
            throw new \InvalidArgumentException('Solo los créditos grupales permiten aplicar un abono a un integrante.');
        }

        $integrante = $credito->distribucionesIntegrantes()
            ->where('id_cliente', $idCliente)
            ->first();
        if (!$integrante) {
            throw new \InvalidArgumentException('El integrante no forma parte de la distribución vigente del crédito grupal.');
        }

        $abonosIntegrante = Pago::where('num_prog', $credito->num_prog)
            ->where('tipo', 'Abono')
            ->where('id_cliente_integrante', $idCliente)
            ->when($pagoExcluido, fn ($query) => $query->where('id', '!=', $pagoExcluido))
            ->sum('monto');
        $asignadoIntegrante = \App\Models\PagoGrupalAsignacion::where('id_cliente_integrante', $idCliente)
            ->whereIn('pago_id', Pago::where('num_prog', $credito->num_prog)->where('tipo', 'Abono')->pluck('id'))
            ->sum('monto');
        $saldoIntegrante = max(0, round((float) $integrante->total - (float) $abonosIntegrante - (float) $asignadoIntegrante, 2));
        if ($monto > $saldoIntegrante + 0.009) {
            throw new \InvalidArgumentException("El abono supera el saldo individual de {$integrante->nombre_cliente} (\${$saldoIntegrante}).");
        }

        $abonosGrupo = (float) Pago::where('num_prog', $credito->num_prog)
            ->where('tipo', 'Abono')
            ->when($pagoExcluido, fn ($query) => $query->where('id', '!=', $pagoExcluido))
            ->sum('monto');
        $saldoGrupo = max(0, round((float) $credito->total - (float) ($credito->abonos_historicos ?? 0) - $abonosGrupo, 2));
        if ($monto > $saldoGrupo + 0.009) {
            throw new \InvalidArgumentException("El abono supera el saldo pendiente del grupo (\${$saldoGrupo}).");
        }
    }

    public function historial(Credito $credito)
    {
        return $credito->pagos()
            ->with(['registradoPor:id,name', 'integrante:id_cliente,nombre_completo', 'asignacionesGrupales'])
            ->orderByDesc('fecha')
            ->orderByDesc('hora')
            ->orderByDesc('id')
            ->get();
    }

    /** Actualiza un abono sin alterar la recepción de efectivo ya confirmada. */
    public function actualizarAbono(Credito $credito, Pago $pago, array $data): Pago
    {
        return DB::transaction(function () use ($credito, $pago, $data) {
            $this->validarMontoIntegrante(
                $credito,
                $pago->id_cliente_integrante,
                (float) $data['monto'],
                $pago->id,
            );
            $pago->update([
                'monto' => $data['monto'],
                'fecha' => $data['fecha'],
                'hora' => $data['hora'] ?? $pago->hora,
                'metodo_pago' => $data['metodo_pago'],
                'notas' => $data['notas'] ?? null,
            ]);

            $this->syncCredito($credito);

            return $pago->fresh('registradoPor');
        });
    }

    /**
     * Anula un abono capturado por error antes de que sea recibido en caja.
     * Después de la recepción, el pago forma parte de un ingreso contable y
     * debe conservarse para que el corte y la caja no queden desincronizados.
     */
    public function eliminarAbono(Credito $credito, Pago $pago): void
    {
        if ($pago->tipo !== 'Abono') {
            throw new \InvalidArgumentException('Solo los abonos pueden eliminarse desde el corte diario.');
        }

        if (MovimientoCaja::where('pago_id', $pago->id)->exists()) {
            throw new \InvalidArgumentException('No se puede eliminar este abono porque ya fue recibido y registrado en caja.');
        }

        DB::transaction(function () use ($credito, $pago) {
            $ahorroPersonalMonto = abs((float) $pago->ahorro_personal_monto);
            if ($ahorroPersonalMonto > 0 && $credito->id_asesor) {
                $ahorro = AhorroPersonal::where('asesor_id', $credito->id_asesor)
                    ->lockForUpdate()
                    ->first();

                if ($ahorro) {
                    $ahorro->update(['saldo' => max(0, (float) $ahorro->saldo - $ahorroPersonalMonto)]);
                    AhorroPersonalMovimiento::where('ahorro_personal_id', $ahorro->id)
                        ->where('notas', "Ahorro desde abono #{$pago->id} / crédito #{$credito->num_prog}")
                        ->delete();
                }
            }

            $pago->delete();
            $this->syncCredito($credito);
        });
    }

    private function registrarAhorroPersonalDesdePago(Credito $credito, Pago $pago, float $monto): void
    {
        if ($monto < 0.01 || ! $credito->id_asesor) {
            return;
        }

        $ahorro = AhorroPersonal::firstOrCreate(
            ['asesor_id' => $credito->id_asesor],
            ['saldo' => 0]
        );

        $ahorro->increment('saldo', $monto);

        AhorroPersonalMovimiento::create([
            'ahorro_personal_id' => $ahorro->id,
            'tipo' => 'Ingreso',
            'monto' => $monto,
            'fecha' => $pago->fecha,
            'notas' => "Ahorro desde abono #{$pago->id} / crédito #{$credito->num_prog}",
            'registrado_por' => Auth::id(),
        ]);
    }
}
