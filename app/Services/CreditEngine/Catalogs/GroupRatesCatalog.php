<?php

namespace App\Services\CreditEngine\Catalogs;

class GroupRatesCatalog
{
    public const RATE_TCGN10 = 'TCGN10';
    public const RATE_TCGP07 = 'TCGP07';
    public const RATE_TCGPE04 = 'TCGPE04';
    public const RATE_TCGPEV01 = 'TCGPEV01';
    public const RATE_TCGEC00 = 'TCGEC00';

    /**
     * Mapeo de factores de pago semanal por cada $1,000 autorizados para grupos.
     */
    public static function getInterestRates(): array
    {
        return [
            self::RATE_TCGN10 => [
                14 => 100.00,
                16 => 94.00,
                18 => 88.00,
            ],
            self::RATE_TCGP07 => [
                14 => 97.00,
                16 => 91.00,
                18 => 85.00,
            ],
            self::RATE_TCGPE04 => [
                14 => 94.00,
                16 => 88.00,
                18 => 82.00,
            ],
            self::RATE_TCGPEV01 => [
                14 => 91.00,
                16 => 85.00,
                18 => 79.00,
            ],
            self::RATE_TCGEC00 => [
                14 => 88.00,
                16 => 80.00,
                18 => 76.00,
            ],
        ];
    }

    /**
     * Montos mínimos sugeridos por el catálogo (Nuevo, Competencia, Casa).
     */
    public static function getMinimumAmounts(): array
    {
        return [
            'nuevo' => 9000.0,      // $9k a $12k
            'competencia' => 15000.0,
            'casa' => 30000.0,      // $30k+
        ];
    }

    /**
     * Devuelve plazos permitidos según el ciclo para créditos grupales.
     */
    public static function getAllowedTermsForCycle(int $cycle): array
    {
        if ($cycle === 0) {
            return [14]; // Fijo a 14 semanas
        }
        if ($cycle >= 1 && $cycle <= 2) {
            return [14, 16];
        }
        return [14, 16, 18]; // Ciclo 3 en adelante
    }
}
