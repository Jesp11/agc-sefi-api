<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\CreditEngine\Recommendation\IndividualRecommendationService;
use App\Services\CreditEngine\Recommendation\GroupRecommendationService;

class SimulationController extends Controller
{
    public function simulateIndividual(Request $request, IndividualRecommendationService $recommendationService)
    {
        $validated = $request->validate([
            'ciclo' => 'required|integer|min:0',
            'monto_solicitado' => 'required|numeric|min:0',
            'buen_historial' => 'sometimes|boolean',
            'cantidad_referidos' => 'sometimes|integer|min:0',
            'origen' => 'sometimes|string|in:nuevo,competencia',
        ]);

        $result = $recommendationService->getRecommendation($validated);

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 400);
        }

        return response()->json($result);
    }

    public function simulateGroup(Request $request, GroupRecommendationService $recommendationService)
    {
        $validated = $request->validate([
            'ciclo' => 'required|integer|min:0',
            'monto_total_grupo' => 'required|numeric|min:0',
            'cantidad_integrantes' => 'required|integer|min:1',
            'origen' => 'sometimes|string|in:nuevo,competencia,casa,referido_socio',
            'cantidad_referidos' => 'sometimes|integer|min:0',
        ]);

        $result = $recommendationService->getRecommendation($validated);

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 400);
        }

        return response()->json($result);
    }

    public function getCatalogIndividual()
    {
        return response()->json([
            'tasas' => \App\Services\CreditEngine\Catalogs\IndividualRatesCatalog::getInterestRates(),
            'monto_minimo' => \App\Services\CreditEngine\Catalogs\IndividualRatesCatalog::getMinimumAmount(),
            'origenes' => ['nuevo', 'competencia']
        ]);
    }

    public function getCatalogGroup()
    {
        return response()->json([
            'tasas' => \App\Services\CreditEngine\Catalogs\GroupRatesCatalog::getInterestRates(),
            'montos_minimos' => \App\Services\CreditEngine\Catalogs\GroupRatesCatalog::getMinimumAmounts(),
            'origenes' => array_keys(\App\Services\CreditEngine\Catalogs\GroupRatesCatalog::getMinimumAmounts())
        ]);
    }
}
