<?php

namespace App\Services;

use App\Models\Credito;
use App\Models\IndicadorOperativoEvento;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class IndicadoresOperativosService
{
    public const TIPO_AUMENTO_CARTERA = 'AumentoCartera';
    public const TIPO_PASE_MORA = 'PaseMora';
    public const TIPO_CANCELACION_VEHICULAR = 'CancelacionVehicular';

    public function registrarAumentoCartera(
        string $fecha,
        float $monto,
        ?Credito $credito = null,
        ?Credito $creditoRelacionado = null,
        ?string $origen = null,
        ?string $descripcion = null,
        array $meta = []
    ): ?IndicadorOperativoEvento {
        $monto = round(abs($monto), 2);
        if ($monto < 0.01) {
            return null;
        }

        return $this->registrarEvento(
            tipo: self::TIPO_AUMENTO_CARTERA,
            fecha: $fecha,
            monto: $monto,
            credito: $credito,
            creditoRelacionado: $creditoRelacionado,
            origen: $origen,
            descripcion: $descripcion,
            meta: $meta,
        );
    }

    public function registrarPaseMora(
        string $fecha,
        float $monto,
        Credito $credito,
        ?string $origen = null,
        ?string $descripcion = null,
        array $meta = []
    ): ?IndicadorOperativoEvento {
        $monto = round(abs($monto), 2);
        if ($monto < 0.01) {
            return null;
        }

        return $this->registrarEvento(
            tipo: self::TIPO_PASE_MORA,
            fecha: $fecha,
            monto: $monto,
            credito: $credito,
            origen: $origen,
            descripcion: $descripcion,
            meta: $meta,
        );
    }

    public function registrarCancelacionVehicular(
        string $fecha,
        float $monto,
        ?Credito $credito = null,
        ?string $origen = null,
        ?string $descripcion = null,
        array $meta = []
    ): ?IndicadorOperativoEvento {
        $monto = round(abs($monto), 2);
        if ($monto < 0.01) {
            return null;
        }

        return $this->registrarEvento(
            tipo: self::TIPO_CANCELACION_VEHICULAR,
            fecha: $fecha,
            monto: $monto,
            credito: $credito,
            origen: $origen,
            descripcion: $descripcion,
            meta: $meta,
        );
    }

    public function resumenMensual(Carbon $inicio, Carbon $corte): array
    {
        $rows = IndicadorOperativoEvento::query()
            ->whereBetween('fecha', [$inicio->toDateString(), $corte->toDateString()])
            ->get();

        return [
            'aumento_cartera' => round((float) $rows->where('tipo', self::TIPO_AUMENTO_CARTERA)->sum('monto'), 2),
            'pase_a_cartera_mora' => round((float) $rows->where('tipo', self::TIPO_PASE_MORA)->sum('monto'), 2),
            'eventos' => $rows->map(fn (IndicadorOperativoEvento $row) => [
                'id' => $row->id,
                'fecha' => $row->fecha?->toDateString(),
                'tipo' => $row->tipo,
                'monto' => round((float) $row->monto, 2),
                'num_prog' => $row->num_prog,
                'num_prog_relacionado' => $row->num_prog_relacionado,
                'origen' => $row->origen,
                'descripcion' => $row->descripcion,
                'meta' => $row->meta,
            ])->values()->all(),
        ];
    }

    private function registrarEvento(
        string $tipo,
        string $fecha,
        float $monto,
        ?Credito $credito = null,
        ?Credito $creditoRelacionado = null,
        ?string $origen = null,
        ?string $descripcion = null,
        array $meta = []
    ): IndicadorOperativoEvento {
        return IndicadorOperativoEvento::create([
            'fecha' => Carbon::parse($fecha)->toDateString(),
            'tipo' => $tipo,
            'monto' => round($monto, 2),
            'num_prog' => $credito?->num_prog,
            'num_prog_relacionado' => $creditoRelacionado?->num_prog,
            'origen' => $origen,
            'descripcion' => $descripcion,
            'meta' => $meta,
            'registrado_por' => Auth::id(),
        ]);
    }
}
