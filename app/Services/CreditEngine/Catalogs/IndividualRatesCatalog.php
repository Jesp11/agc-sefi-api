<?php

namespace App\Services\CreditEngine\Catalogs;

class IndividualRatesCatalog
{
    public const RATE_TCIN21 = 'TCIN21';
    public const RATE_TCIP18 = 'TCIP18';
    public const RATE_TCIPE14 = 'TCIPE14';
    public const RATE_TCIPV10 = 'TCIPV10';

    /**
     * Mapeo de factores de pago semanal por cada $1,000 autorizados.
     * Estructura: [Tasa => [Plazo Semanas => Pago Semanal por cada $1,000]]
     */
    public static function getInterestRates(): array
    {
        return [
            self::RATE_TCIN21 => [
                12 => 121.00,
                14 => 109.00,
                16 => 100.00,
            ],
            self::RATE_TCIP18 => [
                12 => 118.00,
                14 => 106.00,
                16 => 97.00,
            ],
            self::RATE_TCIPE14 => [
                12 => 115.00,
                14 => 103.00,
                16 => 94.00,
            ],
            self::RATE_TCIPV10 => [
                12 => 112.00,
                14 => 100.00,
                16 => 91.00,
            ],
        ];
    }

    /**
     * Devuelve los plazos permitidos dado un monto solicitado.
     */
    public static function getAllowedTermsForAmount(float $amount): array
    {
        if ($amount < 3000) {
            return []; // Monto mínimo no alcanzado
        }
        if ($amount >= 3000 && $amount < 4000) {
            return [12];
        }
        if ($amount >= 4000 && $amount < 5000) {
            return [12, 14];
        }
        return [12, 14, 16]; // $5000 en adelante
    }

    public static function getMinimumAmount(): float
    {
        return 3000.0;
    }
}
