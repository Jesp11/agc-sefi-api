<?php

namespace App\Services;

use App\Models\AhorroPersonal;
use App\Models\AhorroPersonalMovimiento;
use App\Models\Asesor;
use Illuminate\Support\Facades\DB;

class AhorroPersonalService
{
    private function ensureAhorrosForAsesores(): void
    {
        foreach (Asesor::pluck('id') as $asesorId) {
            AhorroPersonal::firstOrCreate(['asesor_id' => $asesorId], ['saldo' => 0]);
        }
    }

    private function mapAsesor(Asesor $asesor): array
    {
        return [
            'id' => $asesor->id,
            'nombre' => $asesor->nombre_asesor,
            'codigo' => $asesor->id_asesor,
            'saldo' => round((float) ($asesor->ahorroPersonal?->saldo ?? 0), 2),
            'movimientos' => $asesor->ahorroPersonal?->movimientos ?? [],
        ];
    }

    public function listar(): array
    {
        $this->ensureAhorrosForAsesores();

        $asesores = Asesor::with(['ahorroPersonal.movimientos' => fn ($q) => $q->orderByDesc('fecha')])
            ->orderBy('nombre_asesor')
            ->get()
            ->map(fn ($asesor) => $this->mapAsesor($asesor));

        return [
            'total_saldo' => round((float) AhorroPersonal::sum('saldo'), 2),
            'asesores' => $asesores,
        ];
    }

    public function registrarMovimiento(int $asesorId, array $data, string $tipo): AhorroPersonal
    {
        return DB::transaction(function () use ($asesorId, $data, $tipo) {
            $ahorro = AhorroPersonal::firstOrCreate(
                ['asesor_id' => $asesorId],
                ['saldo' => 0]
            );
            $monto = abs((float) $data['monto']);

            if ($tipo === 'Retiro' && $ahorro->saldo < $monto) {
                throw new \InvalidArgumentException('Saldo insuficiente');
            }

            if ($tipo === 'Ingreso') {
                $ahorro->increment('saldo', $monto);
            } else {
                $ahorro->decrement('saldo', $monto);
            }

            AhorroPersonalMovimiento::create([
                'ahorro_personal_id' => $ahorro->id,
                'tipo' => $tipo,
                'monto' => $monto,
                'fecha' => $data['fecha'],
                'notas' => $data['notas'] ?? null,
                'registrado_por' => auth()->id(),
            ]);

            return $ahorro->fresh(['asesor', 'movimientos']);
        });
    }

    public function resumenAnual(int $anio): array
    {
        $this->ensureAhorrosForAsesores();

        $meses = ['ENE', 'FEB', 'MZO', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];
        $asesores = Asesor::with(['ahorroPersonal.movimientos' => fn ($q) => $q->whereYear('fecha', $anio)])
            ->orderBy('nombre_asesor')
            ->get();

        $filas = $asesores->map(function ($asesor) use ($meses) {
            $movimientos = $asesor->ahorroPersonal?->movimientos ?? collect();
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
                'id' => $asesor->id,
                'nombre' => $asesor->nombre_asesor,
                'codigo' => $asesor->id_asesor,
                'saldo' => round((float) ($asesor->ahorroPersonal?->saldo ?? 0), 2),
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
            'asesores' => $filas,
            'totales_mes' => $totalesMes,
            'total_general' => round($filas->sum('total_anio'), 2),
            'total_saldo' => round((float) AhorroPersonal::sum('saldo'), 2),
        ];
    }

    public function importarAnual(int $anio, array $filas, bool $reemplazar = true): array
    {
        $this->ensureAhorrosForAsesores();

        $meses = ['ENE', 'FEB', 'MZO', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];
        $creados = 0;
        $errores = [];

        DB::transaction(function () use ($anio, $filas, $reemplazar, $meses, &$creados, &$errores) {
            foreach ($filas as $idx => $fila) {
                $codigo = trim((string) ($fila['codigo'] ?? ''));
                if ($codigo === '') {
                    $errores[] = ['fila' => $idx + 1, 'mensaje' => 'Fila sin ID de asesor'];
                    continue;
                }

                $asesor = Asesor::where('id_asesor', $codigo)->first();
                if (!$asesor) {
                    $errores[] = ['fila' => $idx + 1, 'mensaje' => "Asesor no encontrado: {$codigo}"];
                    continue;
                }

                $ahorro = AhorroPersonal::firstOrCreate(
                    ['asesor_id' => $asesor->id],
                    ['saldo' => 0]
                );

                if ($reemplazar) {
                    $this->revertirMovimientosAnio($ahorro, $anio);
                }

                $mesesData = $fila['meses'] ?? [];
                foreach ($meses as $i => $mes) {
                    $monto = $this->parseMonto($mesesData[$mes] ?? null);
                    if ($monto === null || $monto == 0) {
                        continue;
                    }

                    $numMes = $i + 1;
                    $fecha = sprintf('%04d-%02d-01', $anio, $numMes);
                    $tipo = $monto > 0 ? 'Ingreso' : 'Retiro';
                    $abs = abs($monto);

                    if ($tipo === 'Retiro' && $ahorro->saldo < $abs) {
                        $errores[] = [
                            'fila' => $idx + 1,
                            'mensaje' => "Saldo insuficiente para retiro ({$codigo}, {$mes})",
                        ];
                        continue;
                    }

                    if ($tipo === 'Ingreso') {
                        $ahorro->increment('saldo', $abs);
                    } else {
                        $ahorro->decrement('saldo', $abs);
                    }

                    AhorroPersonalMovimiento::create([
                        'ahorro_personal_id' => $ahorro->id,
                        'tipo' => $tipo,
                        'monto' => $abs,
                        'fecha' => $fecha,
                        'notas' => "Importación anual {$anio}",
                        'registrado_por' => auth()->id(),
                    ]);

                    $creados++;
                }
            }
        });

        return [
            'message' => "Importación completada: {$creados} movimiento(s) registrado(s)",
            'creados' => $creados,
            'errores' => $errores,
        ];
    }

    private function revertirMovimientosAnio(AhorroPersonal $ahorro, int $anio): void
    {
        $movimientos = $ahorro->movimientos()->whereYear('fecha', $anio)->get();
        foreach ($movimientos as $mov) {
            if ($mov->tipo === 'Ingreso') {
                $ahorro->decrement('saldo', $mov->monto);
            } else {
                $ahorro->increment('saldo', $mov->monto);
            }
            $mov->delete();
        }
    }

    private function parseMonto(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $str = trim((string) $value);
        if ($str === '') {
            return null;
        }

        if (str_contains($str, '+')) {
            $parts = array_filter(array_map('trim', explode('+', $str)));
            $sum = 0;
            foreach ($parts as $part) {
                if (!is_numeric($part)) {
                    return null;
                }
                $sum += (float) $part;
            }
            return $sum;
        }

        return is_numeric($str) ? (float) $str : null;
    }
}
