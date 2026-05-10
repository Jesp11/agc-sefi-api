<?php

namespace App\Services\CreditEngine\Simulation;

use App\Services\CreditEngine\Catalogs\IndividualRatesCatalog;
use App\Services\CreditEngine\Catalogs\GroupRatesCatalog;

class AmortizationSimulator
{
    /**
     * Simula un crédito individual.
     */
    public function simulateIndividual(string $rate, float $amount, int $termWeeks): array
    {
        $ratesMap = IndividualRatesCatalog::getInterestRates();
        
        if (!isset($ratesMap[$rate][$termWeeks])) {
            throw new \InvalidArgumentException("Combinación de tasa ($rate) y plazo ($termWeeks) no válida para crédito individual.");
        }

        $weeklyPaymentFactor = $ratesMap[$rate][$termWeeks];
        
        // El factor de la tabla es el PAGO SEMANAL por cada $1,000 autorizados
        $weeklyPayment = ($amount / 1000) * $weeklyPaymentFactor;
        $totalToPay = $weeklyPayment * $termWeeks;
        $totalInterest = $totalToPay - $amount;
        $interestPercentage = ($totalInterest / $amount) * 100;

        return [
            'tipo' => 'individual',
            'tasa' => $rate,
            'plazo_semanas' => $termWeeks,
            'monto_solicitado' => round($amount, 2),
            'interes_total' => round($totalInterest, 2),
            'porcentaje_interes' => round($interestPercentage, 2),
            'total_a_pagar' => round($totalToPay, 2),
            'pago_semanal' => round($weeklyPayment, 2),
        ];
    }

    /**
     * Simula un crédito grupal.
     */
    public function simulateGroup(string $rate, float $totalGroupAmount, int $termWeeks, int $membersCount = 1): array
    {
        $ratesMap = GroupRatesCatalog::getInterestRates();
        
        if (!isset($ratesMap[$rate][$termWeeks])) {
            throw new \InvalidArgumentException("Combinación de tasa ($rate) y plazo ($termWeeks) no válida para crédito grupal.");
        }

        $weeklyPaymentFactor = $ratesMap[$rate][$termWeeks];
        
        // El factor de la tabla es el PAGO SEMANAL por cada $1,000 autorizados
        $weeklyPaymentGroup = ($totalGroupAmount / 1000) * $weeklyPaymentFactor;
        $totalToPay = $weeklyPaymentGroup * $termWeeks;
        $totalInterest = $totalToPay - $totalGroupAmount;
        $interestPercentage = ($totalInterest / $totalGroupAmount) * 100;

        // Suponiendo distribución equitativa por integrante (para propósitos de simulación base)
        $amountPerMember = $totalGroupAmount / $membersCount;
        $totalToPayPerMember = $totalToPay / $membersCount;
        $weeklyPaymentPerMember = $weeklyPaymentGroup / $membersCount;

        return [
            'tipo' => 'grupal',
            'tasa' => $rate,
            'plazo_semanas' => $termWeeks,
            'monto_total_grupo' => round($totalGroupAmount, 2),
            'integrantes' => $membersCount,
            'interes_total' => round($totalInterest, 2),
            'porcentaje_interes' => round($interestPercentage, 2),
            'total_a_pagar_grupo' => round($totalToPay, 2),
            'pago_semanal_grupo' => round($weeklyPaymentGroup, 2),
            'desglose_por_integrante_promedio' => [
                'monto_promedio' => round($amountPerMember, 2),
                'total_a_pagar' => round($totalToPayPerMember, 2),
                'pago_semanal' => round($weeklyPaymentPerMember, 2),
            ]
        ];
    }
}
