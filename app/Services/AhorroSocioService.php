<?php

namespace App\Services;

use App\Models\AhorroSocio;
use App\Models\AhorroSocioMovimiento;
use App\Models\Socio;
use Illuminate\Support\Facades\DB;

class AhorroSocioService
{
    private function ensureAhorrosForSocios(): void
    {
        foreach (Socio::where('activo', true)->pluck('id') as $socioId) {
            AhorroSocio::firstOrCreate(['socio_id' => $socioId], ['saldo' => 0]);
        }
    }

    private function mapSocio(Socio $socio): array
    {
        return [
            'id' => $socio->id,
            'nombre' => $socio->nombre,
            'codigo' => $socio->codigo,
            'saldo' => round((float) ($socio->ahorro?->saldo ?? 0), 2),
            'movimientos' => $socio->ahorro?->movimientos ?? [],
        ];
    }

    public function listar(): array
    {
        $this->ensureAhorrosForSocios();

        $socios = Socio::where('activo', true)
            ->with(['ahorro.movimientos' => fn ($q) => $q->orderByDesc('fecha')])
            ->orderBy('nombre')
            ->get()
            ->map(fn ($socio) => $this->mapSocio($socio));

        return [
            'total_saldo' => round((float) AhorroSocio::sum('saldo'), 2),
            'socios' => $socios,
        ];
    }

    public function registrarMovimiento(int $socioId, array $data, string $tipo): AhorroSocio
    {
        return DB::transaction(function () use ($socioId, $data, $tipo) {
            $ahorro = AhorroSocio::where('socio_id', $socioId)->firstOrFail();
            $monto = abs((float) $data['monto']);

            if ($tipo === 'Retiro' && $ahorro->saldo < $monto) {
                throw new \InvalidArgumentException('Saldo insuficiente');
            }

            if ($tipo === 'Ingreso') {
                $ahorro->increment('saldo', $monto);
            } else {
                $ahorro->decrement('saldo', $monto);
            }

            AhorroSocioMovimiento::create([
                'ahorro_socio_id' => $ahorro->id,
                'tipo' => $tipo,
                'monto' => $monto,
                'fecha' => $data['fecha'],
                'notas' => $data['notas'] ?? null,
                'registrado_por' => auth()->id(),
            ]);

            return $ahorro->fresh(['socio', 'movimientos']);
        });
    }

    public function resumenAnual(int $anio): array
    {
        $this->ensureAhorrosForSocios();

        $meses = ['ENE', 'FEB', 'MZO', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];
        $socios = Socio::where('activo', true)
            ->with(['ahorro.movimientos' => fn ($q) => $q->whereYear('fecha', $anio)])
            ->orderBy('nombre')
            ->get();

        $filas = $socios->map(function ($socio) use ($meses) {
            $movimientos = $socio->ahorro?->movimientos ?? collect();
            $porMes = [];
            $totalAnio = 0;

            for ($m = 1; $m <= 12; $m++) {
                $neto = $movimientos
                    ->filter(fn ($mov) => (int) $mov->fecha->format('n') === $m)
                    ->sum(fn ($mov) => $mov->tipo === 'Ingreso' ? (float) $mov->monto : -(float) $mov->monto);
                $porMes[$meses[$m - 1]] = round($neto, 2);
                $totalAnio += $neto;
            }

            return [
                'id' => $socio->id,
                'nombre' => $socio->nombre,
                'codigo' => $socio->codigo,
                'saldo' => round((float) ($socio->ahorro?->saldo ?? 0), 2),
                'meses' => $porMes,
                'total_anio' => round($totalAnio, 2),
            ];
        });

        $totalesMes = [];
        foreach ($meses as $mes) {
            $totalesMes[$mes] = round($filas->sum(fn ($f) => $f['meses'][$mes]), 2);
        }

        return [
            'anio' => $anio,
            'meses' => $meses,
            'socios' => $filas,
            'totales_mes' => $totalesMes,
            'total_general' => round($filas->sum('total_anio'), 2),
            'total_saldo' => round((float) AhorroSocio::sum('saldo'), 2),
        ];
    }
}
