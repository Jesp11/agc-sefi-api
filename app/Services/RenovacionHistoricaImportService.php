<?php

namespace App\Services;

use App\Models\CicloHistorial;
use App\Models\Credito;
use App\Models\Refinanciamiento;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RenovacionHistoricaImportService
{
    public const REQUIRED_COLUMNS = [
        'folio_credito_anterior',
        'folio_credito_nuevo',
        'saldo_absorbido',
        'monto_neto',
    ];

    /**
     * Valida filas ya extraídas del libro de Excel. No escribe en la base de datos.
     */
    public function previsualizar(array $rows, array $columns = []): array
    {
        $columns = array_values(array_unique(array_filter(array_map(
            fn ($column) => $this->normalizeColumn((string) $column),
            $columns
        ))));
        $missingColumns = array_values(array_diff(self::REQUIRED_COLUMNS, $columns));

        $folios = collect($rows)
            ->flatMap(fn ($row) => [
                $this->folio($row['folio_credito_anterior'] ?? null),
                $this->folio($row['folio_credito_nuevo'] ?? null),
            ])
            ->filter()
            ->unique()
            ->values();

        $creditos = Credito::query()
            ->whereIn('num_prog', $folios)
            ->get()
            ->keyBy(fn (Credito $credito) => (string) $credito->num_prog);

        $refinanciamientos = Refinanciamiento::query()
            ->whereIn('num_prog_anterior', $folios)
            ->orWhereIn('num_prog_nuevo', $folios)
            ->get();
        $childrenByParent = Credito::query()
            ->whereIn('credito_padre_id', $folios)
            ->get()
            ->groupBy(fn (Credito $credito) => (string) $credito->credito_padre_id);

        $seenPairs = [];
        $seenAnterior = [];
        $seenNuevo = [];
        $resultRows = [];

        foreach ($rows as $index => $row) {
            $rowNumber = (int) ($row['row_number'] ?? $index + 2);
            $anteriorFolio = $this->folio($row['folio_credito_anterior'] ?? null);
            $nuevoFolio = $this->folio($row['folio_credito_nuevo'] ?? null);
            $saldoAbsorbido = $this->money($row['saldo_absorbido'] ?? null);
            $montoNeto = $this->money($row['monto_neto'] ?? null);
            $interesesRaw = $row['intereses_arrastrados'] ?? null;
            $interesesArrastrados = $interesesRaw === null || trim((string) $interesesRaw) === ''
                ? 0.0
                : $this->money($interesesRaw);
            $errors = [];
            $warnings = [];

            if ($missingColumns !== []) {
                $errors[] = 'Faltan columnas obligatorias en el archivo: ' . implode(', ', $missingColumns) . '.';
            }
            if (!$anteriorFolio) {
                $errors[] = 'El folio del crédito anterior es obligatorio.';
            }
            if (!$nuevoFolio) {
                $errors[] = 'El folio del crédito nuevo es obligatorio.';
            }
            if ($anteriorFolio && $nuevoFolio && $anteriorFolio === $nuevoFolio) {
                $errors[] = 'El crédito anterior y el nuevo deben ser distintos.';
            }
            if ($saldoAbsorbido === null || $saldoAbsorbido < 0) {
                $errors[] = 'El saldo absorbido debe ser un importe no negativo.';
            }
            if ($montoNeto === null || $montoNeto < 0) {
                $errors[] = 'El monto neto debe ser un importe no negativo.';
            }
            if ($interesesArrastrados === null || $interesesArrastrados < 0) {
                $errors[] = 'Los intereses arrastrados deben ser un importe no negativo.';
            }

            $anterior = $anteriorFolio ? $creditos->get($anteriorFolio) : null;
            $nuevo = $nuevoFolio ? $creditos->get($nuevoFolio) : null;
            if ($anteriorFolio && !$anterior) {
                $errors[] = "No existe el crédito anterior con folio #{$anteriorFolio}.";
            }
            if ($nuevoFolio && !$nuevo) {
                $errors[] = "No existe el crédito nuevo con folio #{$nuevoFolio}.";
            }

            $fechaEfectiva = $this->date($row['fecha_efectiva'] ?? null);
            if (($row['fecha_efectiva'] ?? null) !== null && trim((string) $row['fecha_efectiva']) !== '' && !$fechaEfectiva) {
                $errors[] = 'La fecha efectiva no tiene un formato válido.';
            }
            if (!$fechaEfectiva && $nuevo?->fecha_otorgacion) {
                $fechaEfectiva = Carbon::parse($nuevo->fecha_otorgacion)->toDateString();
            }

            $pair = $anteriorFolio && $nuevoFolio ? "{$anteriorFolio}:{$nuevoFolio}" : null;
            if ($pair && isset($seenPairs[$pair])) {
                $errors[] = "La misma relación ya aparece en la fila {$seenPairs[$pair]}.";
            }
            if ($pair) {
                $seenPairs[$pair] = $rowNumber;
            }
            if ($anteriorFolio && isset($seenAnterior[$anteriorFolio]) && $seenAnterior[$anteriorFolio] !== $nuevoFolio) {
                $errors[] = "El crédito anterior #{$anteriorFolio} ya se usa con otro folio en la fila {$seenAnterior[$anteriorFolio . ':row']}.";
            }
            if ($anteriorFolio) {
                $seenAnterior[$anteriorFolio] = $nuevoFolio;
                $seenAnterior[$anteriorFolio . ':row'] = $rowNumber;
            }
            if ($nuevoFolio && isset($seenNuevo[$nuevoFolio]) && $seenNuevo[$nuevoFolio] !== $anteriorFolio) {
                $errors[] = "El crédito nuevo #{$nuevoFolio} ya se usa con otro folio en la fila {$seenNuevo[$nuevoFolio . ':row']}.";
            }
            if ($nuevoFolio) {
                $seenNuevo[$nuevoFolio] = $anteriorFolio;
                $seenNuevo[$nuevoFolio . ':row'] = $rowNumber;
            }

            if ($anterior && $nuevo) {
                $this->validateExistingLinks($anterior, $nuevo, $refinanciamientos, $childrenByParent, $errors);
                $this->addCompatibilityWarnings($anterior, $nuevo, $warnings);
            }

            $data = [
                'row_number' => $rowNumber,
                'folio_credito_anterior' => $anteriorFolio,
                'folio_credito_nuevo' => $nuevoFolio,
                'saldo_absorbido' => $saldoAbsorbido === null ? null : round($saldoAbsorbido, 2),
                'monto_neto' => $montoNeto === null ? null : round($montoNeto, 2),
                'fecha_efectiva' => $fechaEfectiva,
                'intereses_arrastrados' => $interesesArrastrados === null ? null : round($interesesArrastrados, 2),
                'notas' => $this->notes($row['notas'] ?? null),
            ];

            $resultRows[] = [
                'row_number' => $rowNumber,
                'valid' => $errors === [],
                'errors' => $errors,
                'warnings' => $warnings,
                'data' => $data,
                'credito_anterior' => $anterior ? $this->creditSummary($anterior) : null,
                'credito_nuevo' => $nuevo ? $this->creditSummary($nuevo) : null,
            ];
        }

        return [
            'required_columns' => self::REQUIRED_COLUMNS,
            'missing_columns' => $missingColumns,
            'rows' => $resultRows,
            'summary' => [
                'total' => count($resultRows),
                'valid' => count(array_filter($resultRows, fn ($row) => $row['valid'])),
                'invalid' => count(array_filter($resultRows, fn ($row) => !$row['valid'])),
                'warnings' => array_sum(array_map(fn ($row) => count($row['warnings']), $resultRows)),
            ],
        ];
    }

    /**
     * Persiste únicamente los vínculos históricos. No registra pagos, caja ni indicadores.
     */
    public function confirmar(array $rows, array $columns = []): array
    {
        $preview = $this->previsualizar($rows, $columns);
        if (($preview['summary']['invalid'] ?? 0) > 0) {
            throw new RenovacionHistoricaValidationException($preview);
        }

        return DB::transaction(function () use ($preview, $columns) {
            // Revalida dentro de la transacción antes de cambiar estados o vínculos.
            $rows = array_map(fn ($row) => $row['data'], $preview['rows']);
            $verified = $this->previsualizar($rows, $columns);
            if (($verified['summary']['invalid'] ?? 0) > 0) {
                throw new RenovacionHistoricaValidationException($verified);
            }

            $created = 0;
            $updated = 0;
            foreach ($verified['rows'] as $row) {
                $data = $row['data'];
                $anterior = Credito::lockForUpdate()->findOrFail($data['folio_credito_anterior']);
                $nuevo = Credito::lockForUpdate()->findOrFail($data['folio_credito_nuevo']);

                $refinanciamiento = Refinanciamiento::firstOrNew([
                    'num_prog_anterior' => $anterior->num_prog,
                    'num_prog_nuevo' => $nuevo->num_prog,
                ]);
                $wasExisting = $refinanciamiento->exists;
                $refinanciamiento->fill([
                    'saldo_anterior' => $data['saldo_absorbido'],
                    'deduccion' => $data['saldo_absorbido'],
                    'monto_neto' => $data['monto_neto'],
                    'intereses_arrastrados' => $data['intereses_arrastrados'] ?? 0,
                    'fecha_efectiva' => $data['fecha_efectiva'],
                    'notas' => $data['notas'],
                ]);
                $refinanciamiento->save();
                $wasExisting ? $updated++ : $created++;

                $nuevo->update(['credito_padre_id' => $anterior->num_prog]);
                $anterior->update([
                    'estado' => 'Finalizado',
                    'saldo_pendiente' => 0,
                    'fecha_programada_renovacion' => null,
                    'renovacion_autorizada' => 'Pendiente',
                    'renovacion_tasa' => null,
                ]);
                $this->syncCycleHistory($anterior->fresh(), $nuevo->fresh(), $data['fecha_efectiva']);
            }

            return [
                'created' => $created,
                'updated' => $updated,
                'total' => $created + $updated,
            ];
        });
    }

    private function validateExistingLinks(Credito $anterior, Credito $nuevo, $refinanciamientos, $childrenByParent, array &$errors): void
    {
        foreach ($refinanciamientos as $refinanciamiento) {
            $isSamePair = (int) $refinanciamiento->num_prog_anterior === (int) $anterior->num_prog
                && (int) $refinanciamiento->num_prog_nuevo === (int) $nuevo->num_prog;
            if (!$isSamePair && (int) $refinanciamiento->num_prog_anterior === (int) $anterior->num_prog) {
                $errors[] = "El crédito anterior #{$anterior->num_prog} ya está renovado por el folio #{$refinanciamiento->num_prog_nuevo}.";
            }
            if (!$isSamePair && (int) $refinanciamiento->num_prog_nuevo === (int) $nuevo->num_prog) {
                $errors[] = "El crédito nuevo #{$nuevo->num_prog} ya está vinculado al folio #{$refinanciamiento->num_prog_anterior}.";
            }
        }
        if ($nuevo->credito_padre_id && (int) $nuevo->credito_padre_id !== (int) $anterior->num_prog) {
            $errors[] = "El crédito nuevo #{$nuevo->num_prog} ya apunta al folio #{$nuevo->credito_padre_id}.";
        }
        $otherChild = collect($childrenByParent->get((string) $anterior->num_prog, []))
            ->first(fn (Credito $child) => (int) $child->num_prog !== (int) $nuevo->num_prog);
        if ($otherChild) {
            $errors[] = "El crédito anterior #{$anterior->num_prog} ya está vinculado al folio #{$otherChild->num_prog}.";
        }
    }

    private function addCompatibilityWarnings(Credito $anterior, Credito $nuevo, array &$warnings): void
    {
        if ($anterior->id_cliente !== $nuevo->id_cliente) {
            $warnings[] = 'Los créditos pertenecen a clientes distintos.';
        }
        if ((int) $anterior->id_grupo !== (int) $nuevo->id_grupo) {
            $warnings[] = 'Los créditos pertenecen a grupos distintos.';
        }
        if ($anterior->tipo_credito !== $nuevo->tipo_credito) {
            $warnings[] = 'Los créditos tienen tipos de crédito distintos.';
        }
    }

    private function syncCycleHistory(Credito $anterior, Credito $nuevo, string $fechaEfectiva): void
    {
        $snapshot = [
            'resultado' => 'Refinanciado',
            'estado' => 'Finalizado',
            'saldo_pendiente' => 0,
            'monto_otorgado' => round((float) $anterior->monto_otorgado, 2),
            'total' => round((float) $anterior->total, 2),
            'plazos' => (int) $anterior->plazos,
            'valor_ficha' => round((float) $anterior->valor_ficha, 2),
        ];

        $active = CicloHistorial::where('num_prog', $anterior->num_prog)->where('resultado', 'Activo')->first();
        if ($active) {
            $active->update([
                'fecha_fin' => $fechaEfectiva,
                'fecha_consulta' => $fechaEfectiva,
                'resultado' => 'Refinanciado',
                'snapshot' => $snapshot,
            ]);
        } else {
            CicloHistorial::updateOrCreate(
                ['num_prog' => $anterior->num_prog, 'resultado' => 'Refinanciado'],
                [
                    'id_cliente' => $anterior->id_cliente,
                    'id_grupo' => $anterior->id_grupo,
                    'ciclo' => $anterior->ciclo,
                    'fecha_inicio' => Carbon::parse($anterior->fecha_otorgacion)->toDateString(),
                    'fecha_fin' => $fechaEfectiva,
                    'fecha_consulta' => $fechaEfectiva,
                    'snapshot' => $snapshot,
                ]
            );
        }

        if (!CicloHistorial::where('num_prog', $nuevo->num_prog)->where('resultado', 'Activo')->exists()) {
            CicloHistorial::create([
                'id_cliente' => $nuevo->id_cliente,
                'id_grupo' => $nuevo->id_grupo,
                'ciclo' => $nuevo->ciclo,
                'num_prog' => $nuevo->num_prog,
                'fecha_inicio' => Carbon::parse($nuevo->fecha_otorgacion)->toDateString(),
                'fecha_consulta' => now()->toDateString(),
                'resultado' => 'Activo',
            ]);
        }
    }

    private function creditSummary(Credito $credito): array
    {
        return [
            'num_prog' => $credito->num_prog,
            'tipo_credito' => $credito->tipo_credito,
            'id_cliente' => $credito->id_cliente,
            'id_grupo' => $credito->id_grupo,
            'estado' => $credito->estado,
            'fecha_otorgacion' => $credito->fecha_otorgacion
                ? Carbon::parse($credito->fecha_otorgacion)->toDateString()
                : null,
        ];
    }

    private function normalizeColumn(string $column): string
    {
        return strtolower(trim(str_replace("\xEF\xBB\xBF", '', $column)));
    }

    private function folio(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return ctype_digit($value) ? (string) (int) $value : $value;
    }

    private function money(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = str_replace([',', '$', ' '], '', (string) $value);
        return is_numeric($value) ? (float) $value : null;
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

    private function notes(mixed $value): ?string
    {
        $notes = trim((string) $value);
        return $notes === '' ? null : mb_substr($notes, 0, 2000);
    }
}

class RenovacionHistoricaValidationException extends \InvalidArgumentException
{
    public function __construct(public readonly array $preview)
    {
        parent::__construct('La importación contiene filas no válidas.');
    }
}
