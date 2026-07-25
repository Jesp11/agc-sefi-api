<?php

namespace App\Services;

use App\Models\Credito;
use App\Models\Pago;
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
                'registrado_por' => Auth::id(),
            ];

            $tipo = $data['tipo'] ?? 'Abono';
            $montoMulta = (float) ($data['monto_multa'] ?? 0);
            $pagos = [];

            // Compatibilidad: registro solo de multa (sin abono aparte).
            if ($tipo === 'Multa' && $montoMulta <= 0) {
                $multa = Pago::create([
                    ...$base,
                    'monto' => $data['monto'],
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
                'tipo' => 'Abono',
            ]);
            $pagos[] = $abono;
            $this->flujoCajaService->registrarDesdePago($abono, $credito);

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
}
