<?php

namespace App\Services\CreditEngine\Recommendation;

use App\Services\CreditEngine\Eligibility\GroupEligibilityService;
use App\Services\CreditEngine\Catalogs\GroupRatesCatalog;
use App\Services\CreditEngine\Simulation\AmortizationSimulator;

class GroupRecommendationService
{
    protected GroupEligibilityService $eligibilityService;
    protected AmortizationSimulator $simulator;

    public function __construct(GroupEligibilityService $eligibilityService, AmortizationSimulator $simulator)
    {
        $this->eligibilityService = $eligibilityService;
        $this->simulator = $simulator;
    }

    /**
     * Genera una recomendación de crédito basada en el perfil del grupo.
     */
    public function getRecommendation(array $context): array
    {
        $cycle = $context['ciclo'] ?? 0;
        $totalGroupAmount = $context['monto_total_grupo'] ?? 0.0;
        $origin = $context['origen'] ?? 'nuevo';
        $referralsCount = $context['cantidad_referidos'] ?? 0;
        $membersCount = $context['cantidad_integrantes'] ?? 1;

        // Validar montos mínimos según origen
        $minAmounts = GroupRatesCatalog::getMinimumAmounts();
        $requiredMin = $minAmounts[$origin] ?? $minAmounts['nuevo'];

        if ($totalGroupAmount < $requiredMin) {
            return [
                'error' => "El monto mínimo grupal para el origen '{$origin}' es de $$requiredMin",
            ];
        }

        // En ciclo 0 (prueba) solo se permite el monto mínimo correspondiente a su origen
        if ($cycle === 0 && $totalGroupAmount > $requiredMin) {
            return [
                'error' => "En el ciclo 0 (prueba) solo se permite el monto mínimo de $$requiredMin para el origen '{$origin}'",
            ];
        }


        // Obtener la tasa a la que califica el grupo
        $rate = $this->eligibilityService->determineEligibleRate($cycle, $totalGroupAmount, $origin, $referralsCount);

        // Obtener plazos permitidos según el ciclo
        $allowedTerms = GroupRatesCatalog::getAllowedTermsForCycle($cycle);

        // Generar cálculos para cada plazo permitido
        $options = [];
        foreach ($allowedTerms as $term) {
            $options[] = $this->simulator->simulateGroup($rate, $totalGroupAmount, $term, $membersCount);
        }

        return [
            'tasa_elegible' => $rate,
            'plazos_disponibles' => $allowedTerms,
            'opciones_amortizacion' => $options,
            'contexto_evaluado' => [
                'ciclo' => $cycle,
                'monto_total' => $totalGroupAmount,
                'integrantes' => $membersCount,
                'origen' => $origin,
                'referidos' => $referralsCount
            ]
        ];
    }
}
