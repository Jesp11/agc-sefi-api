<?php

namespace App\Services;

use App\Models\Asesor;
use App\Models\Cliente;
use App\Models\Credito;
use App\Models\Grupo;
use Illuminate\Support\Facades\DB;

class ClienteService
{
    private const PLACEHOLDER = 'NO ESPECIFICADO';

    public function generateIdCliente(string $nombre): string
    {
        $words = explode(' ', strtoupper(trim($nombre)));
        $initials = '';
        foreach ($words as $word) {
            if (! empty($word)) {
                $initials .= substr($word, 0, 1);
            }
        }

        $lastCliente = Cliente::where('id_cliente', 'like', $initials . '%')
            ->orderBy('id_cliente', 'desc')
            ->first();

        if ($lastCliente) {
            $number = (int) substr($lastCliente->id_cliente, strlen($initials));
            $newNumber = $number + 1;
        } else {
            $newNumber = 1;
        }

        return $initials . str_pad((string) $newNumber, 3, '0', STR_PAD_LEFT);
    }

    public function cumpleanosFromCurp(string $curp): string
    {
        $curp = strtoupper($curp);
        $year = substr($curp, 4, 2);
        $month = substr($curp, 6, 2);
        $day = substr($curp, 8, 2);
        $currentYear = (int) date('y');
        $fullYear = ((int) $year > $currentYear) ? '19' . $year : '20' . $year;

        return $fullYear . '-' . $month . '-' . $day;
    }

    public function create(array $data): Cliente
    {
        $data['id_cliente'] = $this->generateIdCliente($data['nombre_completo']);
        $data['curp'] = strtoupper($data['curp']);

        if (empty($data['fecha_nacimiento'])) {
            $data['fecha_nacimiento'] = $this->cumpleanosFromCurp($data['curp']);
        }

        $grupoId = $data['id_grupo'] ?? null;
        unset($data['id_grupo']);

        $cliente = Cliente::create($data);

        if ($grupoId) {
            $cliente->grupos()->attach($grupoId);
        }

        return $cliente->load(['grupos', 'asesor']);
    }

    /**
     * @return array{cliente: Cliente, action: 'created'|'updated'}
     */
    public function upsertFromImport(array $data): array
    {
        $curp = strtoupper($data['curp']);
        $cliente = Cliente::where('curp', $curp)->first();

        $payload = [
            'nombre_completo' => $data['nombre_completo'],
            'curp' => $curp,
            'clave_elector' => $data['clave_elector'] ?? self::PLACEHOLDER,
            'telefono' => $data['telefono'] ?? self::PLACEHOLDER,
            'direccion' => $data['direccion'] ?? self::PLACEHOLDER,
            'entre_calles' => $data['entre_calles'] ?? self::PLACEHOLDER,
            'ocupacion' => $data['ocupacion'] ?? self::PLACEHOLDER,
            'direccion_trabajo' => $data['direccion_trabajo'] ?? self::PLACEHOLDER,
            'telefono_trabajo' => $data['telefono_trabajo'] ?? self::PLACEHOLDER,
            'fecha_nacimiento' => $this->cumpleanosFromCurp($curp),
            'id_asesor' => $data['id_asesor'],
        ];

        if ($cliente) {
            $cliente->update($payload);
            $action = 'updated';
        } else {
            $payload['id_cliente'] = $this->generateIdCliente($data['nombre_completo']);
            $cliente = Cliente::create($payload);
            $action = 'created';
        }

        if (! empty($data['id_grupo'])) {
            $cliente->grupos()->sync([$data['id_grupo']]);
        }

        return ['cliente' => $cliente->fresh(['grupos', 'asesor']), 'action' => $action];
    }

    public function resolveAsesorId(array $row): ?int
    {
        if (! empty($row['id_asesor'])) {
            return (int) $row['id_asesor'];
        }

        if (empty($row['nombre_asesor'])) {
            return null;
        }

        $nombre = trim($row['nombre_asesor']);
        $asesor = Asesor::whereRaw('UPPER(nombre_asesor) = ?', [strtoupper($nombre)])->first();
        if ($asesor) {
            return $asesor->id;
        }

        $lookup = $this->normalizePersonName($nombre);
        $tokens = array_values(array_filter(explode(' ', $lookup)));
        if (empty($tokens)) {
            return null;
        }

        $candidatos = Asesor::query()->get(['id', 'nombre_asesor']);
        $matches = $candidatos->filter(function (Asesor $item) use ($tokens) {
            $normalized = $this->normalizePersonName($item->nombre_asesor);
            $parts = array_values(array_filter(explode(' ', $normalized)));
            foreach ($tokens as $token) {
                if (! in_array($token, $parts, true)) {
                    return false;
                }
            }
            return true;
        })->values();

        if ($matches->count() === 1) {
            return $matches->first()->id;
        }

        if (count($tokens) === 1) {
            $single = $tokens[0];
            $prefixMatches = $candidatos->filter(function (Asesor $item) use ($single) {
                $normalized = $this->normalizePersonName($item->nombre_asesor);
                return $normalized === $single || str_starts_with($normalized, $single . ' ');
            })->values();

            if ($prefixMatches->count() === 1) {
                return $prefixMatches->first()->id;
            }
        }

        return null;
    }

    private function normalizePersonName(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    public function resolveGrupoId(array $row): ?int
    {
        if (! empty($row['id_grupo'])) {
            return (int) $row['id_grupo'];
        }

        if (empty($row['nombre_grupo'])) {
            return null;
        }

        $nombre = trim($row['nombre_grupo']);
        $grupo = Grupo::whereRaw('UPPER(nombre_grupo) = ?', [strtoupper($nombre)])->first();

        return $grupo?->id;
    }

    public function reactivar(Cliente $cliente): Cliente
    {
        $cliente->update([
            'estatus' => 'Activo',
            'fecha_cierre' => null,
        ]);

        return $cliente->fresh();
    }

    public function marcarCerradoSinRenovacion(Cliente $cliente): void
    {
        $cliente->update([
            'estatus' => 'CerradoSinRenovacion',
            'fecha_cierre' => now()->toDateString(),
        ]);
    }

    public function procesarCreditosFinalizados(): int
    {
        $dias = (int) DB::table('configuracion_sistema')
            ->where('clave', 'dias_cierre_sin_renovacion')
            ->value('valor') ?? 30;

        $count = 0;
        $creditosFinalizados = Credito::where('estado', 'Finalizado')
            ->where('updated_at', '<=', now()->subDays($dias))
            ->whereNotNull('id_cliente')
            ->get();

        foreach ($creditosFinalizados as $credito) {
            $tieneNuevoCredito = Credito::where('id_cliente', $credito->id_cliente)
                ->where('num_prog', '>', $credito->num_prog)
                ->exists();

            if (!$tieneNuevoCredito) {
                $cliente = Cliente::find($credito->id_cliente);
                if ($cliente && $cliente->estatus === 'Activo') {
                    $this->marcarCerradoSinRenovacion($cliente);
                    $count++;
                }
            }
        }

        return $count;
    }

    public function historial(Cliente $cliente): array
    {
        $creditos = $cliente->creditos()
            ->with(['asesor', 'pagos', 'refinanciamientos'])
            ->orderByDesc('fecha_otorgacion')
            ->get();

        $moraService = app(MoraCalculationService::class);

        return [
            'cliente' => $cliente->load('asesor'),
            'estatus' => $cliente->estatus,
            'creditos' => $creditos->map(function ($credito) use ($moraService) {
                $credito->load('pagos');
                return array_merge($credito->toArray(), [
                    'mora' => $moraService->calculate($credito),
                ]);
            }),
            'ciclos' => $cliente->ciclosHistorial()->orderByDesc('ciclo')->get(),
        ];
    }
}
