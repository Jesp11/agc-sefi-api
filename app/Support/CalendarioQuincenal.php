<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Calendario de pagos quincenales en días fijos del mes (p. ej. 15 y 30).
 * Si un día no existe en el mes (30 en febrero, 31 en abril) el pago vence
 * el último día de ese mes.
 */
final class CalendarioQuincenal
{
    public const DIA_1_DEFAULT = 15;
    public const DIA_2_DEFAULT = 30;

    /** Fecha del día indicado dentro del mes, ajustada al último día si no existe. */
    public static function fechaEnMes(int $anio, int $mes, int $dia): Carbon
    {
        $inicio = Carbon::create($anio, $mes, 1)->startOfDay();

        return $inicio->day(min($dia, $inicio->daysInMonth));
    }

    /**
     * Las primeras `$plazos` fechas de pago a partir de `$primerPago` (inclusive).
     *
     * @return Carbon[]
     */
    public static function fechas(Carbon|string $primerPago, int $plazos, int $dia1, int $dia2): array
    {
        $desde = Carbon::parse($primerPago)->startOfDay();
        $dias = [min($dia1, $dia2), max($dia1, $dia2)];
        $fechas = [];
        $mes = $desde->copy()->startOfMonth();

        while (count($fechas) < $plazos) {
            foreach ($dias as $dia) {
                $fecha = self::fechaEnMes($mes->year, $mes->month, $dia);
                $ultima = end($fechas);
                // En febrero dos días pueden ajustarse a la misma fecha.
                if ($fecha->lt($desde) || ($ultima && $fecha->isSameDay($ultima))) {
                    continue;
                }
                $fechas[] = $fecha;
                if (count($fechas) === $plazos) {
                    break;
                }
            }
            $mes->addMonthNoOverflow();
        }

        return $fechas;
    }

    public static function esDiaDePago(Carbon|string $fecha, int $dia1, int $dia2): bool
    {
        $fecha = Carbon::parse($fecha)->startOfDay();

        foreach ([$dia1, $dia2] as $dia) {
            if (self::fechaEnMes($fecha->year, $fecha->month, $dia)->isSameDay($fecha)) {
                return true;
            }
        }

        return false;
    }
}
