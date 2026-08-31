<?php

namespace App\Services;

use App\Models\MovimientoCaja;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlujoCajaImportService
{
    private const REFERENCE_PREFIX = 'WEB-FLUJO';

    public function __construct(
        private readonly FlujoCajaService $flujoCajaService,
        private readonly AsesorService $asesorService,
    ) {}

    public function importar(int $anio, int $mes, array $rows, bool $reemplazar = true): array
    {
        $stats = [
            'rows_seen' => 0,
            'created' => 0,
            'deleted' => 0,
            'duplicated' => 0,
            'skipped_extra_initial_balance' => 0,
            'skipped_empty' => 0,
            'warnings' => [],
            'errors' => [],
        ];

        $referenceMonthPrefix = $this->referencePrefixForMonth($anio, $mes);
        $recalcFrom = sprintf('%04d-%02d-01', $anio, $mes);
        $saldoInicialRegistrado = false;

        DB::transaction(function () use ($rows, $reemplazar, $referenceMonthPrefix, $recalcFrom, &$stats, &$saldoInicialRegistrado) {
            if ($reemplazar) {
                $query = MovimientoCaja::query()
                    ->where('referencia', 'like', $referenceMonthPrefix . '%');
                $stats['deleted'] = (clone $query)->count();
                $query->delete();
            }

            foreach ($rows as $index => $row) {
                $stats['rows_seen']++;

                $fecha = trim((string) ($row['fecha'] ?? ''));
                $motivo = trim((string) ($row['motivo'] ?? ''));
                $sheetName = trim((string) ($row['sheet_name'] ?? ''));
                $rowNumber = (int) ($row['row_number'] ?? 0);

                if ($fecha === '' || $motivo === '' || $sheetName === '' || $rowNumber < 1) {
                    $stats['errors'][] = [
                        'fila' => $index + 2,
                        'mensaje' => 'La fila importada no contiene fecha, motivo u origen suficientes.',
                    ];
                    continue;
                }

                $movimientos = $this->buildMovimientos($row);
                if ($movimientos === []) {
                    $stats['skipped_empty']++;
                    continue;
                }

                $asesorId = $this->resolveAsesorId((string) ($row['vendedor'] ?? ''), $stats, $rowNumber);
                $sheetSlug = Str::upper(Str::slug($sheetName, '_'));

                foreach ($movimientos as $suffix => $movimiento) {
                    $categoria = $this->flujoCajaService->inferirCategoria($motivo, $movimiento['tipo']);
                    if ($categoria === 'SaldoInicial') {
                        if ($saldoInicialRegistrado) {
                            $stats['skipped_extra_initial_balance']++;
                            $stats['warnings'][] = [
                                'fila' => $rowNumber,
                                'mensaje' => 'Se omitió un SALDO MES adicional; solo se toma el primero de la hoja.',
                            ];
                            continue;
                        }

                        $saldoInicialRegistrado = true;
                    }

                    $referencia = sprintf(
                        '%s-%s-%s-%d%s',
                        $referenceMonthPrefix,
                        $sheetSlug,
                        $rowNumber,
                        $suffix + 1,
                        $movimiento['tipo'] === 'Ingreso' ? 'I' : 'E'
                    );

                    if (MovimientoCaja::query()->where('referencia', $referencia)->exists()) {
                        $stats['duplicated']++;
                        continue;
                    }

                    MovimientoCaja::create([
                        'fecha' => $fecha,
                        'id_asesor' => $asesorId,
                        'motivo' => $motivo,
                        'tipo' => $movimiento['tipo'],
                        'monto' => $movimiento['monto'],
                        'saldo_resultante' => null,
                        'categoria' => $categoria,
                        'cuenta' => null,
                        'num_prog' => null,
                        'pago_id' => null,
                        'referencia' => $referencia,
                        'registrado_por' => auth()->id(),
                    ]);

                    $stats['created']++;
                }
            }

            $this->flujoCajaService->recalcularSaldosDesde($recalcFrom);
        });

        return $stats;
    }

    private function buildMovimientos(array $row): array
    {
        $desembolso = round(abs((float) ($row['desembolso'] ?? 0)), 2);
        $ingreso = round((float) ($row['ingreso'] ?? 0), 2);

        $items = [];

        if ($desembolso > 0 && $ingreso > 0) {
            $items[] = ['tipo' => 'Egreso', 'monto' => $desembolso];
            $items[] = ['tipo' => 'Ingreso', 'monto' => $ingreso];
            return $items;
        }

        if ($desembolso > 0) {
            $items[] = ['tipo' => 'Egreso', 'monto' => $desembolso];
            return $items;
        }

        if ($ingreso > 0) {
            $items[] = ['tipo' => 'Ingreso', 'monto' => $ingreso];
            return $items;
        }

        if ($ingreso < 0) {
            $items[] = ['tipo' => 'Egreso', 'monto' => round(abs($ingreso), 2)];
        }

        return $items;
    }

    private function resolveAsesorId(string $nombre, array &$stats, int $rowNumber): ?int
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            return null;
        }

        $asesor = $this->asesorService->resolveExistingFromImport([
            'nombre_asesor' => $nombre,
        ]);

        if (! $asesor) {
            $stats['warnings'][] = [
                'fila' => $rowNumber,
                'mensaje' => "No se encontró asesor para '{$nombre}'. Se importó el movimiento sin asesor.",
            ];

            return null;
        }

        return $asesor->id;
    }

    private function referencePrefixForMonth(int $anio, int $mes): string
    {
        return sprintf('%s-%04d-%02d', self::REFERENCE_PREFIX, $anio, $mes);
    }
}
