<?php

namespace App\Services;

use App\Models\AhorroPersonal;
use App\Models\AhorroPersonalMovimiento;
use App\Models\Credito;
use App\Models\Pago;
use App\Support\RoleHelper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PagoService
{
    public function __construct(
        private MoraCalculationService $moraService,
        private FlujoCajaService $flujoCajaService
    ) {}

    /**
     * Registra un abono y, opcionalmente, una multa en la misma operación.
     *
     * @return array{pago: Pago, pagos: list<Pago>, multa: Pago|null}
     */
    public function registrar(Credito $credito, array $data): array
    {
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

            $abono = Pago::create([
                ...$base,
                'monto' => $data['monto'],
                'ahorro_personal_monto' => $ahorroPersonalMonto,
                'tipo' => 'Abono',
            ]);
            $pagos[] = $abono;
            $this->flujoCajaService->registrarDesdePago($abono, $credito);
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

    public function historial(Credito $credito)
    {
        return $credito->pagos()
            ->with('registradoPor:id,name')
            ->orderByDesc('fecha')
            ->orderByDesc('hora')
            ->orderByDesc('id')
            ->get();
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
