<?php

namespace App\Services;

use App\Models\Asesor;
use App\Models\Cliente;
use App\Models\CicloHistorial;
use App\Models\Credito;
use App\Models\Grupo;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CarteraMoraImportService
{
    public function __construct(
        private readonly AsesorService $asesorService,
        private readonly ClienteService $clienteService,
        private readonly MoraCalculationService $moraCalculationService,
    ) {}

    public function importar(array $rows): array
    {
        $stats = [
            'rows_seen' => 0,
            'individual_rows' => 0,
            'group_rows' => 0,
            'clientes_created' => 0,
            'clientes_updated' => 0,
            'grupos_created' => 0,
            'grupos_updated' => 0,
            'vinculos_grupo' => 0,
            'creditos_created' => 0,
            'creditos_updated' => 0,
            'asesores_created' => 0,
            'warnings' => [],
            'errors' => [],
        ];

        DB::transaction(function () use ($rows, &$stats) {
            $grouped = [];

            foreach ($rows as $index => $row) {
                $stats['rows_seen']++;
                $tipo = strtoupper(trim((string) ($row['tipo_credito'] ?? '')));

                if ($tipo === 'GRUPAL') {
                    $stats['group_rows']++;
                    $groupKey = implode('|', [
                        trim((string) ($row['sheet_name'] ?? '')),
                        trim((string) ($row['grupo'] ?? '')),
                        trim((string) ($row['fecha'] ?? '')),
                        (string) ((int) ($row['ciclo'] ?? 0)),
                    ]);
                    $grouped[$groupKey][] = $row;
                    continue;
                }

                $stats['individual_rows']++;
                $this->importIndividualRow($row, $stats, $index + 1);
            }

            foreach ($grouped as $batchRows) {
                $this->importGroupBatch($batchRows, $stats);
            }
        });

        return $stats;
    }

    private function importIndividualRow(array $row, array &$stats, int $rowNumber): void
    {
        $nombre = $this->normalizeName((string) ($row['cliente'] ?? ''));
        if ($nombre === '') {
            $stats['errors'][] = ['fila' => $rowNumber, 'mensaje' => 'Fila individual sin nombre de cliente.'];
            return;
        }

        $asesor = $this->resolveAsesor((string) ($row['asesor'] ?? ''), $stats);
        $cliente = $this->resolveOrCreateCliente($row, $asesor->id, $stats);
        $fechaOtorgacion = (string) ($row['fecha'] ?? '');
        $ciclo = (int) ($row['ciclo'] ?? 0);

        if ($fechaOtorgacion === '' || $ciclo < 0) {
            $stats['errors'][] = ['fila' => $rowNumber, 'mensaje' => "Fila individual inválida para {$nombre}."];
            return;
        }

        $credito = Credito::query()
            ->where('tipo_credito', 'Individual')
            ->where('id_cliente', $cliente->id_cliente)
            ->whereDate('fecha_otorgacion', $fechaOtorgacion)
            ->where('ciclo', $ciclo)
            ->first();

        $payload = $this->buildCreditPayload(
            $row,
            'Individual',
            $asesor->id,
            $cliente->id_cliente,
            null
        );

        if ($credito) {
            $credito->update($payload);
            $stats['creditos_updated']++;
        } else {
            $credito = Credito::create($payload);
            $this->upsertCicloHistorial($credito);
            $stats['creditos_created']++;
        }

        $this->syncMoraState($credito);
    }

    private function importGroupBatch(array $rows, array &$stats): void
    {
        $first = $rows[0] ?? null;
        if (! is_array($first)) {
            return;
        }

        $groupName = trim((string) ($first['grupo'] ?? ''));
        if ($groupName === '') {
            $stats['errors'][] = ['fila' => (int) ($first['row_number'] ?? 0), 'mensaje' => 'Lote grupal sin nombre de grupo.'];
            return;
        }

        $asesor = $this->resolveAsesor((string) ($first['asesor'] ?? ''), $stats);
        $grupo = $this->resolveOrCreateGrupo($groupName, $asesor->id, $stats);

        foreach ($rows as $row) {
            $cliente = $this->resolveOrCreateCliente($row, $asesor->id, $stats);
            if (! $grupo->clientes()->where('clientes.id_cliente', $cliente->id_cliente)->exists()) {
                $grupo->clientes()->attach($cliente->id_cliente);
                $stats['vinculos_grupo']++;
            }
        }

        $fechaOtorgacion = (string) ($first['fecha'] ?? '');
        $ciclo = (int) ($first['ciclo'] ?? 0);

        $monto = round(collect($rows)->sum(fn (array $row) => (float) ($row['monto_otorgado'] ?? 0)), 2);
        $interes = round(collect($rows)->sum(fn (array $row) => (float) ($row['interes'] ?? 0)), 2);
        $total = round(collect($rows)->sum(fn (array $row) => (float) ($row['total'] ?? 0)), 2);
        $saldoTotal = round(collect($rows)->sum(fn (array $row) => (float) ($row['saldo_total'] ?? 0)), 2);
        $saldoInversion = round(collect($rows)->sum(fn (array $row) => (float) ($row['saldo_inversion'] ?? 0)), 2);
        $valorFicha = round(collect($rows)->sum(fn (array $row) => (float) ($row['valor_ficha'] ?? 0)), 2);
        $plazos = (int) max(1, collect($rows)->max(fn (array $row) => (int) ($row['plazos'] ?? 0)));

        $credito = Credito::query()
            ->where('tipo_credito', 'Grupal')
            ->where('id_grupo', $grupo->id)
            ->whereDate('fecha_otorgacion', $fechaOtorgacion)
            ->where('ciclo', $ciclo)
            ->first();

        $payload = $this->buildCreditPayload([
            ...$first,
            'monto_otorgado' => $monto,
            'interes' => $interes,
            'total' => $total,
            'saldo_total' => $saldoTotal,
            'saldo_inversion' => $saldoInversion,
            'valor_ficha' => $valorFicha,
            'plazos' => $plazos,
            'integrantes' => array_map(function (array $row) {
                return [
                    'id_cliente' => $row['id_cliente'] ?? null,
                    'cliente' => $row['cliente'] ?? null,
                    'monto_otorgado' => (float) ($row['monto_otorgado'] ?? 0),
                    'interes' => (float) ($row['interes'] ?? 0),
                    'total' => (float) ($row['total'] ?? 0),
                    'saldo_total' => (float) ($row['saldo_total'] ?? 0),
                    'saldo_inversion' => (float) ($row['saldo_inversion'] ?? 0),
                ];
            }, $rows),
        ], 'Grupal', $asesor->id, null, $grupo->id);

        if ($credito) {
            $credito->update($payload);
            $stats['creditos_updated']++;
        } else {
            $credito = Credito::create($payload);
            $this->upsertCicloHistorial($credito);
            $stats['creditos_created']++;
        }

        $this->syncMoraState($credito);
    }

    private function buildCreditPayload(array $row, string $tipo, int $asesorId, ?string $clienteId, ?int $grupoId): array
    {
        $fechaOtorgacion = (string) $row['fecha'];
        $diasPago = $this->normalizeDay((string) ($row['dias_pago'] ?? 'LUNES'));
        $monto = round((float) ($row['monto_otorgado'] ?? 0), 2);
        $interes = round((float) ($row['interes'] ?? 0), 2);
        $total = round((float) ($row['total'] ?? 0), 2);
        if ($total <= 0 && ($monto > 0 || $interes > 0)) {
            $total = round($monto + $interes, 2);
        }

        $saldoPendiente = round((float) ($row['saldo_total'] ?? $total), 2);
        $plazos = max(1, (int) ($row['plazos'] ?? 16));
        $valorFicha = round((float) ($row['valor_ficha'] ?? 0), 2);
        $ciclo = (int) ($row['ciclo'] ?? 0);
        $fechaPrimerPago = $this->calculateFirstPaymentDate($fechaOtorgacion, $diasPago);
        $moraClasificacion = trim((string) ($row['clasificacion_mora'] ?? 'mora_activa'));

        $metadata = [
            [
                'import_ref' => $this->buildImportRef($tipo, $row),
                'source_sheet' => (string) ($row['sheet_name'] ?? ''),
                'mora_clasificacion' => $moraClasificacion,
                'saldo_inversion_importado' => round((float) ($row['saldo_inversion'] ?? 0), 2),
                'integrantes' => $row['integrantes'] ?? null,
            ],
        ];

        return [
            'id_cliente' => $clienteId,
            'id_grupo' => $grupoId,
            'id_asesor' => $asesorId,
            'fecha_otorgacion' => $fechaOtorgacion,
            'fecha_primer_pago' => $fechaPrimerPago,
            'ciclo' => $ciclo,
            'ciclo_inicio_mora' => 1,
            'monto_otorgado' => $monto,
            'interes' => $interes,
            'total' => $total,
            'saldo_pendiente' => max(0, $saldoPendiente),
            'plazos' => $plazos,
            'valor_ficha' => $valorFicha,
            'dias_pago' => $diasPago,
            'tipo_credito' => $tipo,
            'estado' => 'EnMora',
            'es_personalizado' => true,
            'es_adicional' => false,
            'comision_apertura' => 100,
            'porcentaje_interes' => $monto > 0 ? round(($interes / $monto) * 100, 2) : 0,
            'tabla_amortizacion' => $metadata,
        ];
    }

    private function resolveOrCreateCliente(array $row, int $asesorId, array &$stats): Cliente
    {
        $nombre = $this->normalizeName((string) ($row['cliente'] ?? ''));
        $inputId = $this->normalizeClientCode((string) ($row['id_cliente'] ?? ''));

        $existingByName = Cliente::query()->get()->first(function (Cliente $cliente) use ($nombre) {
            return $this->normalizeName($cliente->nombre_completo) === $nombre;
        });

        if ($existingByName) {
            if ($existingByName->id_asesor !== $asesorId || $existingByName->nombre_completo !== $nombre) {
                $existingByName->update([
                    'id_asesor' => $asesorId,
                    'nombre_completo' => $nombre,
                ]);
                $stats['clientes_updated']++;
            }

            return $existingByName->fresh();
        }

        $resolvedId = $this->resolveClientId($inputId, $nombre, $stats);
        $cliente = Cliente::find($resolvedId);

        if ($cliente) {
            $cliente->update([
                'id_asesor' => $asesorId,
                'nombre_completo' => $nombre,
            ]);
            $stats['clientes_updated']++;

            return $cliente->fresh();
        }

        $cliente = Cliente::create([
            'id_cliente' => $resolvedId,
            'id_asesor' => $asesorId,
            'nombre_completo' => $nombre,
            'curp' => $this->generateUniquePlaceholderCurp('CL', $resolvedId),
            'clave_elector' => $this->normalizeClientCode((string) ($row['clave_elector'] ?? '')) ?: 'S/N',
            'telefono' => 'S/N',
            'direccion' => 'S/N',
            'entre_calles' => 'S/N',
            'ocupacion' => 'NO ESPECIFICADO',
            'direccion_trabajo' => 'S/N',
            'telefono_trabajo' => 'S/N',
            'fecha_nacimiento' => '1990-01-01',
            'estatus' => 'Activo',
        ]);
        $stats['clientes_created']++;

        return $cliente;
    }

    private function resolveClientId(string $inputId, string $nombre, array &$stats): string
    {
        if ($inputId !== '') {
            $existing = Cliente::find($inputId);
            if (! $existing) {
                return $inputId;
            }

            if ($this->normalizeName($existing->nombre_completo) === $nombre) {
                return $inputId;
            }

            $generated = $this->clienteService->generateIdCliente($nombre);
            $stats['warnings'][] = [
                'mensaje' => "Se ignoró ID CLIENTE {$inputId} porque ya pertenece a {$existing->nombre_completo}. Se usó {$generated} para {$nombre}.",
            ];

            return $generated;
        }

        return $this->clienteService->generateIdCliente($nombre);
    }

    private function resolveOrCreateGrupo(string $groupName, int $asesorId, array &$stats): Grupo
    {
        $existing = Grupo::query()
            ->whereRaw('UPPER(nombre_grupo) = ?', [mb_strtoupper($groupName, 'UTF-8')])
            ->first();

        if ($existing) {
            if ((int) $existing->id_asesor !== $asesorId) {
                $existing->update(['id_asesor' => $asesorId]);
                $stats['grupos_updated']++;
            }

            return $existing->fresh();
        }

        $grupo = Grupo::create([
            'nombre_grupo' => trim($groupName),
            'id_asesor' => $asesorId,
            'es_socio_preferencial' => false,
        ]);
        $stats['grupos_created']++;

        return $grupo;
    }

    private function resolveAsesor(string $nombre, array &$stats): Asesor
    {
        $nombre = trim($nombre) !== '' ? trim($nombre) : 'GESTOR MORA';
        $asesor = $this->asesorService->resolveExistingFromImport([
            'nombre_asesor' => $nombre,
        ]);

        if ($asesor) {
            return $asesor;
        }

        $asesor = Asesor::create([
            'id_asesor' => $this->asesorService->generateIdAsesor($nombre, 'Gestor de Cobranza'),
            'nombre_asesor' => $nombre,
            'curp' => $this->generateUniquePlaceholderCurp('AS', $nombre),
            'cumpleanos' => '1990-01-01',
            'telefono' => null,
            'rol_laboral' => 'Gestor de Cobranza',
        ]);
        $stats['asesores_created']++;

        return $asesor;
    }

    private function upsertCicloHistorial(Credito $credito): void
    {
        $payload = [
            'id_cliente' => $credito->id_cliente,
            'id_grupo' => $credito->id_grupo,
            'ciclo' => $credito->ciclo,
            'fecha_inicio' => $credito->fecha_otorgacion,
            'fecha_fin' => null,
            'resultado' => 'Activo',
        ];

        $existing = CicloHistorial::query()->where('num_prog', $credito->num_prog)->first();
        if ($existing) {
            $existing->update($payload);
            return;
        }

        CicloHistorial::create([
            ...$payload,
            'num_prog' => $credito->num_prog,
        ]);
    }

    private function syncMoraState(Credito $credito): void
    {
        $fresh = $credito->fresh()->load('pagos');
        $this->moraCalculationService->syncCreditoState($fresh);
        if ($fresh->estado !== 'EnMora') {
            $fresh->update(['estado' => 'EnMora']);
        }
    }

    private function calculateFirstPaymentDate(string $fechaOtorgacion, string $diasPago): string
    {
        $date = Carbon::parse($fechaOtorgacion)->startOfDay();
        $targetDay = match ($diasPago) {
            'LUNES' => 1,
            'MARTES' => 2,
            'MIERCOLES' => 3,
            'JUEVES' => 4,
            'VIERNES' => 5,
            'SABADO' => 6,
            'DOMINGO' => 0,
            default => null,
        };

        if ($targetDay === null) {
            return $date->toDateString();
        }

        while ((int) $date->dayOfWeek !== $targetDay) {
            $date->addDay();
        }

        return $date->toDateString();
    }

    private function buildImportRef(string $tipo, array $row): string
    {
        $target = $tipo === 'Grupal'
            ? trim((string) ($row['grupo'] ?? ''))
            : trim((string) ($row['cliente'] ?? ''));

        return sprintf(
            'EXCEL-MORA-WEB-%s-%s-%s-C%s',
            strtoupper($tipo === 'Grupal' ? 'GRP' : 'IND'),
            Str::upper(Str::slug($target, '_')),
            (string) ($row['fecha'] ?? ''),
            (string) ((int) ($row['ciclo'] ?? 0))
        );
    }

    private function normalizeName(string $value): string
    {
        $value = mb_strtoupper(trim($value), 'UTF-8');
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $transliterated !== false ? $transliterated : $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function normalizeClientCode(string $value): string
    {
        $value = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($value), 'UTF-8')) ?? '';
        return trim($value);
    }

    private function normalizeDay(string $value): string
    {
        $value = $this->normalizeName($value);
        return match ($value) {
            'MIERCOLES', 'MIERCOLES ' => 'MIERCOLES',
            default => $value !== '' ? $value : 'LUNES',
        };
    }

    private function generateUniquePlaceholderCurp(string $prefix, string $seed): string
    {
        $base = preg_replace('/[^A-Z0-9]/', '', $this->normalizeName($seed)) ?? '';
        $base = str_pad(substr($base, 0, 8), 8, 'X');
        $seq = 1;

        do {
            $curp = substr($prefix . $base . str_pad((string) $seq, 8, '0', STR_PAD_LEFT), 0, 18);
            $seq++;
        } while (
            Asesor::query()->where('curp', $curp)->exists()
            || Cliente::query()->where('curp', $curp)->exists()
        );

        return $curp;
    }
}
