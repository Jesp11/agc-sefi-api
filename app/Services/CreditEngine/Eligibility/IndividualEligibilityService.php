<?php

namespace App\Services\CreditEngine\Eligibility;

use App\Services\CreditEngine\Catalogs\IndividualRatesCatalog;

class IndividualEligibilityService
{
    /**
     * Evalúa la elegibilidad del cliente basado en su ciclo, historial y referidos.
     * Retorna la tasa a la que es elegible.
     */
    public function determineEligibleRate(int $cycle, bool $goodPaymentHistory, int $referralsCount): string
    {
        // Ciclo 0 (Cliente Nuevo)
        if ($cycle === 0) {
            return IndividualRatesCatalog::RATE_TCIN21;
        }

        // Lógica a partir del ciclo 4
        if ($cycle >= 12 && $goodPaymentHistory) {
            // Nota: Podrían haber requisitos adicionales para TCIPV10, los asumimos cumplidos si llega aquí
            return IndividualRatesCatalog::RATE_TCIPV10;
        }

        if ($cycle >= 8 && $goodPaymentHistory && $referralsCount >= 3) {
            return IndividualRatesCatalog::RATE_TCIPE14;
        }

        if ($cycle >= 4 && $goodPaymentHistory && $referralsCount >= 2) {
            return IndividualRatesCatalog::RATE_TCIP18;
        }

        // Si no cumple los requisitos para subir, se mantiene o regresa a la base (dependiendo de reglas de penalización)
        // Por defecto, si tiene retrasos o no le alcanzan los referidos, podría seguir en la anterior o en la base.
        // Asumimos que se queda en TCIN21 si no clasifica a ninguna preferencial.
        return IndividualRatesCatalog::RATE_TCIN21;
    }
}
