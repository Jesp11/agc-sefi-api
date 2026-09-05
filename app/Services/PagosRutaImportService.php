<?php

namespace App\Services;

use App\Models\Credito;
use App\Models\Pago;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Validates and imports the payments marked in the daily-route workbook.
 * The workbook is deliberately only an instruction to pay: amounts and route
 * eligibility are always recalculated from the current portfolio.
 */
class PagosRutaImportService
{
    public const REQUIRED_COLUMNS = [
        'folio', 'cuota', 'fecha_cuota', 'importe_esperado', 'fecha_pago',
        'referencia_ruta', 'pago_realizado', 'metodo_pago',
    ];

    private const METODOS_VALIDOS = ['Efectivo', 'Transferencia', 'Otro'];

    public function __construct(
        private CarteraService $carteraService,
        private MoraCalculationService $moraService,
        private PagoService $pagoService,
    ) {}

    public static function referenciaRuta(int|string $folio, int|string $cuota, string $fechaCuota): string
    {
        return 'RUTA-'.(int) $folio.'-'.(int) $cuota.'-'.str_replace('-', '', $fechaCuota);
    }

    public function previsualizar(string $fecha, array $rows, array $columns = []): array
    {
        $fecha = Carbon::parse($fecha)->toDateString();
        $columns = array_values(array_unique(array_filter(array_map(
            fn ($column) => $this->normalizarColumna((string) $column),
            $columns
        ))));
        $missingColumns = array_values(array_diff(self::REQUIRED_COLUMNS, $columns));

        $routeByReference = $this->rutaActual($fecha);
        $folios = collect($rows)->map(fn ($row) => $this->folio($row['folio'] ?? null))->filter()->unique();
        $creditos = Credito::with(['cliente', 'grupo', 'asesor', 'pagos'])
            ->whereIn('num_prog', $folios)
            ->get()
            ->keyBy(fn (Credito $credito) => (string) $credito->num_prog);

        $references = collect($rows)
            ->map(fn ($row) => trim((string) ($row['referencia_ruta'] ?? '')))
            ->filter()
            ->unique()
            ->values();
        $alreadyImported = Pago::query()
            ->whereIn('referencia_importacion', $references)
            ->pluck('referencia_importacion')
            ->flip();

        $seenReferences = [];
        $resultRows = [];
        foreach ($rows as $index => $source) {
            $rowNumber = (int) ($source['row_number'] ?? $index + 2);
            $data = $this->normalizarFila($source, $rowNumber);
            $errors = [];
            $warnings = [];
            $action = 'ignore';

            if ($missingColumns !== []) {
                $errors[] = 'Faltan columnas obligatorias: '.implode(', ', $missingColumns).'.';
            }
            if ($data['pago_realizado'] === null) {
                $errors[] = 'Pago realizado debe ser SI o NO.';
            }

            // A NO row never creates a movement. It is still displayed in the
            // preview so operators can confirm that it was intentionally ignored.
            if ($data['pago_realizado'] === 'NO') {
                $resultRows[] = $this->resultRow($rowNumber, $data, $errors, $warnings, $action, null);

                continue;
            }
            if ($data['pago_realizado'] !== 'SI') {
                $resultRows[] = $this->resultRow($rowNumber, $data, $errors, $warnings, $action, null);

                continue;
            }

            if (! $data['folio']) {
                $errors[] = 'El folio es obligatorio.';
            }
            if (! $data['cuota']) {
                $errors[] = 'El número de cuota es obligatorio.';
            }
            if (! $data['fecha_cuota']) {
                $errors[] = 'La fecha de cuota no es válida.';
            }
            if ($data['importe_esperado'] === null || $data['importe_esperado'] <= 0) {
                $errors[] = 'El importe esperado debe ser mayor a cero.';
            }
            if (! $data['fecha_pago']) {
                $errors[] = 'La fecha de pago no es válida.';
            }
            if ($data['fecha_pago'] && $data['fecha_pago'] !== $fecha) {
                $errors[] = "La fecha de pago debe ser {$fecha}.";
            }
            if (! $data['referencia_ruta']) {
                $errors[] = 'La referencia de ruta es obligatoria.';
            }
            if (! $data['metodo_pago'] || ! in_array($data['metodo_pago'], self::METODOS_VALIDOS, true)) {
                $errors[] = 'El método de pago debe ser Efectivo, Transferencia u Otro.';
            }

            $credito = $data['folio'] ? $creditos->get((string) $data['folio']) : null;
            if ($data['folio'] && ! $credito) {
                $errors[] = "No existe el crédito con folio #{$data['folio']}.";
            }

            $expectedReference = ($data['folio'] && $data['cuota'] && $data['fecha_cuota'])
                ? self::referenciaRuta($data['folio'], $data['cuota'], $data['fecha_cuota'])
                : null;
            if ($expectedReference && $data['referencia_ruta'] !== $expectedReference) {
                $errors[] = 'La referencia de ruta no corresponde al folio y cuota indicados.';
            }
            if ($data['referencia_ruta'] && isset($seenReferences[$data['referencia_ruta']])) {
                $errors[] = "La misma referencia ya aparece en la fila {$seenReferences[$data['referencia_ruta']]} .";
            }
            if ($data['referencia_ruta']) {
                $seenReferences[$data['referencia_ruta']] = $rowNumber;
            }

            $routeItem = $data['referencia_ruta'] ? ($routeByReference[$data['referencia_ruta']] ?? null) : null;
            if ($routeItem) {
                if (! $this->sameMoney($data['importe_esperado'], $routeItem['importe'])) {
                    $errors[] = 'El importe esperado fue modificado o ya no coincide con la cuota programada.';
                }
            }

            if ($data['referencia_ruta'] && isset($alreadyImported[$data['referencia_ruta']])) {
                $warnings[] = 'Esta cuota ya fue importada anteriormente; se omitirá.';
                $action = 'skip';
            } elseif ($credito && $data['cuota'] && $data['fecha_cuota']) {
                $pendiente = $this->pendienteDeCuota($credito, $data['cuota'], $data['fecha_cuota']);
                if ($pendiente === null) {
                    $errors[] = 'La cuota no existe en el calendario actual del crédito.';
                } elseif ($pendiente <= 0.009) {
                    $warnings[] = 'La cuota ya fue cubierta por un pago manual; se omitirá.';
                    $action = 'skip';
                } elseif (! $routeItem) {
                    $errors[] = 'La cuota ya no está pendiente en la ruta seleccionada.';
                } elseif (! $this->sameMoney($pendiente, $routeItem['importe'])) {
                    $errors[] = 'La cuota recibió un abono y ya no conserva el importe exacto de la ruta.';
                } else {
                    $action = 'create';
                }
            }

            $resultRows[] = $this->resultRow($rowNumber, $data, $errors, $warnings, $action, $credito);
        }

        return [
            'fecha' => $fecha,
            'required_columns' => self::REQUIRED_COLUMNS,
            'missing_columns' => $missingColumns,
            'rows' => $resultRows,
            'summary' => [
                'total' => count($resultRows),
                'selected' => count(array_filter($resultRows, fn ($row) => $row['data']['pago_realizado'] === 'SI')),
                'valid' => count(array_filter($resultRows, fn ($row) => $row['valid'])),
                'invalid' => count(array_filter($resultRows, fn ($row) => ! $row['valid'])),
                'created' => count(array_filter($resultRows, fn ($row) => $row['valid'] && $row['action'] === 'create')),
                'omitted' => count(array_filter($resultRows, fn ($row) => $row['action'] === 'skip')),
                'warnings' => array_sum(array_map(fn ($row) => count($row['warnings']), $resultRows)),
            ],
        ];
    }

    public function confirmar(string $fecha, array $rows, array $columns = []): array
    {
        $preview = $this->previsualizar($fecha, $rows, $columns);
        if (($preview['summary']['invalid'] ?? 0) > 0) {
            throw new PagosRutaImportValidationException($preview);
        }

        return DB::transaction(function () use ($fecha, $preview, $columns) {
            // Revalidate under the transaction so a manual capture cannot race
            // this import between the preview and confirmation clicks.
            $sourceRows = array_map(fn ($row) => $row['data'], $preview['rows']);
            $verified = $this->previsualizar($fecha, $sourceRows, $columns);
            if (($verified['summary']['invalid'] ?? 0) > 0) {
                throw new PagosRutaImportValidationException($verified);
            }

            $created = 0;
            $omitted = 0;
            foreach ($verified['rows'] as $row) {
                if ($row['action'] === 'ignore' || $row['action'] === 'skip') {
                    $omitted++;

                    continue;
                }

                $data = $row['data'];
                $credito = Credito::with(['cliente', 'grupo', 'asesor'])->lockForUpdate()->findOrFail($data['folio']);
                $this->pagoService->registrar($credito, [
                    'monto' => $data['importe_esperado'],
                    'fecha' => Carbon::parse($fecha)->toDateString(),
                    'hora' => now()->format('H:i:s'),
                    'metodo_pago' => $data['metodo_pago'],
                    'notas' => $data['notas'],
                    'referencia_importacion' => $data['referencia_ruta'],
                ]);
                $created++;
            }

            return [
                'created' => $created,
                'omitted' => $omitted,
                'total' => $created + $omitted,
                'preview' => $verified,
            ];
        });
    }

    private function rutaActual(string $fecha): array
    {
        $route = $this->carteraService->cobrosDelDia($fecha)['cobros'] ?? [];
        $result = [];
        foreach ($route as $item) {
            if (($item['estado'] ?? null) === 'EnMora') {
                continue;
            }
            $pending = $item['pendientes'][0] ?? null;
            if (! $pending || empty($item['num_prog']) || empty($pending['semana']) || empty($pending['fecha'])) {
                continue;
            }
            $reference = self::referenciaRuta($item['num_prog'], $pending['semana'], $pending['fecha']);
            $result[$reference] = [
                'importe' => round((float) ($pending['monto'] ?? 0), 2),
                'item' => $item,
            ];
        }

        return $result;
    }

    /** Remaining balance of exactly one scheduled installment, applying all abonos oldest first. */
    private function pendienteDeCuota(Credito $credito, int $numeroCuota, string $fechaCuota): ?float
    {
        $schedule = $this->moraService->generateSchedule($credito);
        $paid = round((float) $credito->pagos->where('tipo', 'Abono')->sum('monto'), 2);
        foreach ($schedule as $cuota) {
            if ((int) $cuota['semana'] !== $numeroCuota || $cuota['fecha'] !== $fechaCuota) {
                continue;
            }
            $prior = 0.0;
            foreach ($schedule as $previous) {
                if ((int) $previous['semana'] >= $numeroCuota) {
                    break;
                }
                $prior += (float) $previous['pago'];
            }

            return round(max(0, (float) $cuota['pago'] - max(0, $paid - $prior)), 2);
        }

        return null;
    }

    private function normalizarFila(array $row, int $rowNumber): array
    {
        $metodo = trim((string) ($row['metodo_pago'] ?? ''));
        $metodo = match (mb_strtolower($metodo, 'UTF-8')) {
            'efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'otro' => 'Otro', default => $metodo,
        };
        $mark = Str::upper(Str::ascii(trim((string) ($row['pago_realizado'] ?? ''))));

        return [
            'row_number' => $rowNumber,
            'folio' => $this->folio($row['folio'] ?? null),
            'cliente_grupo' => trim((string) ($row['cliente_grupo'] ?? '')) ?: null,
            'gestor' => trim((string) ($row['gestor'] ?? '')) ?: null,
            'categoria' => trim((string) ($row['categoria'] ?? '')) ?: null,
            'cuota' => $this->integer($row['cuota'] ?? null),
            'fecha_cuota' => $this->date($row['fecha_cuota'] ?? null),
            'importe_esperado' => $this->money($row['importe_esperado'] ?? null),
            'fecha_pago' => $this->date($row['fecha_pago'] ?? null),
            'referencia_ruta' => trim((string) ($row['referencia_ruta'] ?? '')) ?: null,
            'pago_realizado' => in_array($mark, ['SI', 'NO'], true) ? $mark : null,
            'metodo_pago' => $metodo ?: null,
            'notas' => ($notes = trim((string) ($row['notas'] ?? ''))) === '' ? null : mb_substr($notes, 0, 2000),
        ];
    }

    private function resultRow(int $rowNumber, array $data, array $errors, array $warnings, string $action, ?Credito $credito): array
    {
        return [
            'row_number' => $rowNumber,
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'action' => $action,
            'data' => $data,
            'credito' => $credito ? [
                'num_prog' => $credito->num_prog,
                'cliente' => $credito->cliente?->nombre_completo ?? $credito->grupo?->nombre_grupo,
                'estado' => $credito->estado,
            ] : null,
        ];
    }

    private function normalizarColumna(string $column): string
    {
        $key = preg_replace('/[^a-z0-9]+/', '_', strtolower(Str::ascii(trim($column))));
        $key = trim((string) $key, '_');

        return match ($key) {
            'numero_cuota', 'numero_de_cuota' => 'cuota',
            'fecha_de_cuota' => 'fecha_cuota',
            'fecha_de_pago' => 'fecha_pago',
            'referencia_de_ruta' => 'referencia_ruta',
            'metodo_de_pago' => 'metodo_pago',
            default => $key,
        };
    }

    private function folio(mixed $value): ?int
    {
        $value = trim((string) $value);

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function integer(mixed $value): ?int
    {
        $value = trim((string) $value);

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function money(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = str_replace([',', '$', ' '], '', (string) $value);

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function sameMoney(?float $one, ?float $other): bool
    {
        return $one !== null && $other !== null && abs($one - $other) < 0.01;
    }
}

class PagosRutaImportValidationException extends \InvalidArgumentException
{
    public function __construct(public readonly array $preview)
    {
        parent::__construct('La importación contiene filas no válidas.');
    }
}
