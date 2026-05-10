<?php

namespace App\Services\CreditEngine\Recommendation;

use App\Services\CreditEngine\Eligibility\IndividualEligibilityService;
use App\Services\CreditEngine\Catalogs\IndividualRatesCatalog;
use App\Services\CreditEngine\Simulation\AmortizationSimulator;

class IndividualRecommendationService
{
    protected IndividualEligibilityService $eligibilityService;
    protected AmortizationSimulator $simulator;

    public function __construct(IndividualEligibilityService $eligibilityService, AmortizationSimulator $simulator)
    {
        $this->eligibilityService = $eligibilityService;
        $this->simulator = $simulator;
    }

    /**
     * Genera una recomendación de crédito basada en el perfil del cliente.
     */
    public function getRecommendation(array $context): array
    {
        $cycle = $context['ciclo'] ?? 0;
        $amount = $context['monto_solicitado'] ?? 0.0;
        $goodPaymentHistory = $context['buen_historial'] ?? true;
        $referralsCount = $context['cantidad_referidos'] ?? 0;
        $origin = $context['origen'] ?? 'nuevo';

        // Validar monto mínimo base
        $minAmount = IndividualRatesCatalog::getMinimumAmount();
        if ($amount < $minAmount) {
            return [
                'error' => "El monto mínimo solicitado es de $$minAmount",
            ];
        }

        // En ciclo 0 (prueba) solo se permite el monto mínimo
        if ($cycle === 0 && $amount > $minAmount) {
            return [
                'error' => "En el ciclo 0 (prueba) solo se permite el monto mínimo de $$minAmount",
            ];
        }


        // Obtener la tasa a la que califica
        // Si viene de competencia (ejemplo) podría saltar directo a TCIP18, si así lo dicta la regla
        $rate = $this->eligibilityService->determineEligibleRate($cycle, $goodPaymentHistory, $referralsCount);

        if ($origin === 'competencia') {
            $rate = IndividualRatesCatalog::RATE_TCIP18; // Sobrescribe por regla especial
        }

        // Obtener plazos permitidos para el monto
        $allowedTerms = IndividualRatesCatalog::getAllowedTermsForAmount($amount);

        // Generar cálculos para cada plazo permitido
        $options = [];
        foreach ($allowedTerms as $term) {
            $options[] = $this->simulator->simulateIndividual($rate, $amount, $term);
        }

        return [
            'tasa_elegible' => $rate,
            'plazos_disponibles' => $allowedTerms,
            'opciones_amortizacion' => $options,
            'contexto_evaluado' => [
                'ciclo' => $cycle,
                'monto' => $amount,
                'historial_positivo' => $goodPaymentHistory,
                'referidos' => $referralsCount,
                'origen' => $origin
            ]
        ];
    }
}
