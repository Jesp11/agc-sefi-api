<?php

namespace App\Services;

use App\Models\Aportacion;
use App\Models\Inversionista;
use App\Models\MovimientoCaja;
use App\Models\MovimientoCapital;
use Illuminate\Support\Facades\DB;

class InversionistaImportService
{
    private const NOTE_PREFIX = 'Importacion Excel inversionistas';
    private const CAPITAL_REF_PREFIX = 'INV-IMPORT-CAPITAL';
    private const RENDIMIENTO_REF_PREFIX = 'INV-IMPORT-REND';

    public function importar(array $rows): array
    {
        $stats = [
            'rows_seen' => 0,
            'created' => 0,
            'updated' => 0,
            'aportaciones_deleted' => 0,
            'aportaciones_created' => 0,
            'movimientos_capital_deleted' => 0,
            'movimientos_capital_created' => 0,
            'rendimientos_deleted' => 0,
            'rendimientos_created' => 0,
            'warnings' => [],
            'errors' => [],
        ];

        DB::transaction(function () use ($rows, &$stats) {
            foreach ($rows as $index => $row) {
                $stats['rows_seen']++;
                $nombre = trim((string) ($row['nombre'] ?? ''));
                if ($nombre === '') {
                    $stats['errors'][] = ['fila' => $index + 2, 'mensaje' => 'Fila sin nombre de inversionista'];
                    continue;
                }

                $inversionista = $this->resolveInversionista($nombre);
                $action = $inversionista ? 'updated' : 'created';
                $inversionista = $this->upsertInversionista($inversionista, $nombre);
                $stats[$action]++;

                $deleted = $this->deletePreviousImports($inversionista->id);
                $stats['aportaciones_deleted'] += $deleted['aportaciones'];
                $stats['movimientos_capital_deleted'] += $deleted['movimientos_capital'];
                $stats['rendimientos_deleted'] += $deleted['rendimientos'];

                $capital = round((float) ($row['inversion_inicial'] ?? 0), 2);
                $rendimientos = is_array($row['rendimientos'] ?? null) ? $row['rendimientos'] : [];

                if ($capital > 0) {
                    $fechaCapital = $rendimientos[0]['fecha'] ?? '2025-01-01';
                    $this->registrarCapitalInicial($inversionista->id, $nombre, $capital, $fechaCapital);
                    $stats['aportaciones_created']++;
                    $stats['movimientos_capital_created']++;
                }

                foreach ($rendimientos as $item) {
                    $fecha = (string) ($item['fecha'] ?? '');
                    $monto = round((float) ($item['monto'] ?? 0), 2);
                    if ($fecha === '' || $monto <= 0) {
                        continue;
                    }

                    if ($this->hasExcelMovementsForMonth($fecha)) {
                        continue;
                    }

                    $this->registrarRendimiento($inversionista->id, $nombre, $monto, $fecha);
                    $stats['rendimientos_created']++;
                }

                $totalExcel = round((float) ($row['total_excel'] ?? 0), 2);
                $totalCalc = round(collect($rendimientos)->sum(fn (array $item) => (float) ($item['monto'] ?? 0)), 2);
                if ($totalExcel > 0 && abs($totalExcel - $totalCalc) > 0.01) {
                    $stats['warnings'][] = [
                        'fila' => $index + 2,
                        'mensaje' => "TOTAL excel={$totalExcel} difiere de suma de rendimientos={$totalCalc} para {$nombre}",
                    ];
                }
            }
        });

        return $stats;
    }

    private function resolveInversionista(string $nombre): ?Inversionista
    {
        $normalized = $this->normalizeName($nombre);
        $all = Inversionista::all();

        $exact = $all->first(fn (Inversionista $item) => $this->normalizeName($item->nombre) === $normalized);
        if ($exact) {
            return $exact;
        }

        $matches = $all->filter(fn (Inversionista $item) => $this->containsAllTokens($normalized, $this->normalizeName($item->nombre)))->values();
        if ($matches->count() === 1) {
            return $matches->first();
        }

        return null;
    }

    private function upsertInversionista(?Inversionista $inversionista, string $nombre): Inversionista
    {
        if ($inversionista) {
            $preferred = mb_strlen(trim($inversionista->nombre)) >= mb_strlen(trim($nombre))
                ? $inversionista->nombre
                : $nombre;
            $inversionista->update([
                'nombre' => $preferred,
                'activo' => true,
            ]);

            return $inversionista->fresh();
        }

        return Inversionista::create([
            'nombre' => mb_strtoupper($nombre, 'UTF-8'),
            'tipo_entidad' => 'Persona Fisica',
            'activo' => true,
        ]);
    }

    private function deletePreviousImports(int $inversionistaId): array
    {
        $aportaciones = Aportacion::query()
            ->where('inversionista_id', $inversionistaId)
            ->where('notas', 'like', self::NOTE_PREFIX . '%');
        $aportacionesCount = (clone $aportaciones)->count();
        $aportaciones->delete();

        $capital = MovimientoCapital::query()
            ->where('referencia', 'like', self::CAPITAL_REF_PREFIX . '-' . $inversionistaId . '-%');
        $capitalCount = (clone $capital)->count();
        $capital->delete();

        $rendimientos = MovimientoCaja::query()
            ->where('referencia', 'like', self::RENDIMIENTO_REF_PREFIX . '-' . $inversionistaId . '-%');
        $rendimientosCount = (clone $rendimientos)->count();
        $rendimientos->delete();

        return [
            'aportaciones' => $aportacionesCount,
            'movimientos_capital' => $capitalCount,
            'rendimientos' => $rendimientosCount,
        ];
    }

    private function registrarCapitalInicial(int $inversionistaId, string $nombre, float $monto, string $fecha): void
    {
        Aportacion::create([
            'inversionista_id' => $inversionistaId,
            'monto' => $monto,
            'fecha' => $fecha,
            'tipo' => 'Aportacion',
            'notas' => self::NOTE_PREFIX . ' capital inicial',
            'registrado_por' => auth()->id(),
        ]);

        MovimientoCapital::create([
            'tipo' => 'Aportacion',
            'monto' => $monto,
            'referencia' => self::CAPITAL_REF_PREFIX . '-' . $inversionistaId . '-' . $fecha,
            'fecha' => $fecha,
            'descripcion' => self::NOTE_PREFIX . ' capital inicial — ' . $nombre,
            'registrado_por' => auth()->id(),
        ]);
    }

    private function registrarRendimiento(int $inversionistaId, string $nombre, float $monto, string $fecha): void
    {
        MovimientoCaja::create([
            'fecha' => $fecha,
            'motivo' => 'RENDIMIENTO INVERSIONISTA — ' . mb_strtoupper($nombre, 'UTF-8'),
            'tipo' => 'Egreso',
            'monto' => $monto,
            'saldo_resultante' => null,
            'categoria' => 'Rendimiento',
            'cuenta' => 'Inversionistas',
            'referencia' => self::RENDIMIENTO_REF_PREFIX . '-' . $inversionistaId . '-' . $fecha,
            'registrado_por' => auth()->id(),
        ]);
    }

    private function hasExcelMovementsForMonth(string $fecha): bool
    {
        $year = (int) substr($fecha, 0, 4);
        $month = (int) substr($fecha, 5, 2);

        return MovimientoCaja::query()
            ->where(function ($query) {
                $query->where('referencia', 'like', 'EXCEL-%')
                    ->orWhere('referencia', 'like', 'WEB-FLUJO-%');
            })
            ->whereYear('fecha', $year)
            ->whereMonth('fecha', $month)
            ->exists();
    }

    private function normalizeName(string $value): string
    {
        $value = mb_strtoupper(trim($value), 'UTF-8');
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $transliterated !== false ? $transliterated : $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function containsAllTokens(string $lookup, string $candidate): bool
    {
        $lookupTokens = array_values(array_filter(explode(' ', $lookup)));
        $candidateTokens = array_values(array_filter(explode(' ', $candidate)));
        foreach ($lookupTokens as $token) {
            if (! in_array($token, $candidateTokens, true)) {
                return false;
            }
        }

        return $lookupTokens !== [];
    }
}
