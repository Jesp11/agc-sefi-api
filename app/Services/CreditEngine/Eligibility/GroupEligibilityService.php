<?php

namespace App\Services\CreditEngine\Eligibility;

use App\Services\CreditEngine\Catalogs\GroupRatesCatalog;

class GroupEligibilityService
{
    /**
     * Evalúa la elegibilidad del grupo basado en su ciclo, monto total acumulado y origen especial.
     */
    public function determineEligibleRate(int $cycle, float $totalGroupAmount, string $origin = 'nuevo', int $referralsCount = 0): string
    {
        // Accesos especiales (Sobrescriben el ciclo)
        if ($origin === 'referido_socio') {
            return GroupRatesCatalog::RATE_TCGEC00;
        }

        if ($origin === 'competencia') {
            return GroupRatesCatalog::RATE_TCGP07;
        }
        
        if ($referralsCount >= 3) {
            // Nota: El documento menciona "3 referidos -> TCGPE04" en acceso especial
            return GroupRatesCatalog::RATE_TCGPE04;
        }

        // Progresión normal por ciclos y monto
        if ($cycle >= 12 && $totalGroupAmount >= 60000) {
            return GroupRatesCatalog::RATE_TCGPEV01;
        }

        if ($cycle >= 8 && $totalGroupAmount >= 35000) {
            return GroupRatesCatalog::RATE_TCGPE04;
        }

        if ($cycle >= 4 && $totalGroupAmount >= 27000) {
            return GroupRatesCatalog::RATE_TCGP07;
        }

        // Ciclo 0 o si no cumple metas de montos
        return GroupRatesCatalog::RATE_TCGN10;
    }
}
