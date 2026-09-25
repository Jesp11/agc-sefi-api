<?php

namespace Tests\Feature;

use App\Services\BalanceGeneralCarteraService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BalanceGeneralCarteraTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('balance_general_cartera_mensual');

        parent::tearDown();
    }

    public function test_importa_los_valores_historicos_de_enero_a_agosto_de_2026(): void
    {
        $migration = require database_path('migrations/2026_09_25_000002_create_balance_general_cartera_mensual.php');
        $migration->up();

        $this->assertDatabaseCount('balance_general_cartera_mensual', 8);
        $this->assertDatabaseHas('balance_general_cartera_mensual', [
            'mes' => '2026-01', 'valor_neto' => 648913.73, 'valor_bruto' => 1090913.73,
        ]);
        $this->assertDatabaseHas('balance_general_cartera_mensual', [
            'mes' => '2026-08', 'valor_neto' => 506229.71, 'valor_bruto' => 877458.71,
        ]);
    }

    public function test_la_serie_combina_historia_cierre_y_mes_en_curso_sin_sobrescribir_valores_manuales(): void
    {
        $migration = require database_path('migrations/2026_09_25_000002_create_balance_general_cartera_mensual.php');
        $migration->up();
        $service = app(BalanceGeneralCarteraService::class);

        $serie = $service->serieAnual(2026, '2026-09', 950000, 700000);
        $this->assertCount(12, $serie);
        $this->assertSame(['mes' => '2026-01', 'valor_bruto' => 1090913.73, 'valor_neto' => 648913.73, 'origen' => 'historico'], $serie[0]);
        $this->assertSame(['mes' => '2026-09', 'valor_bruto' => 950000.0, 'valor_neto' => 700000.0, 'origen' => 'en_curso'], $serie[8]);
        $this->assertNull($serie[9]['valor_bruto']);

        $service->registrarCierre('2026-09', 960000, 710000);
        $service->registrarCierre('2026-01', 1, 1);
        $serie = $service->serieAnual(2026, '2026-09', 950000, 700000);

        $this->assertSame('cierre', $serie[8]['origen']);
        $this->assertSame(960000.0, $serie[8]['valor_bruto']);
        $this->assertSame(648913.73, $serie[0]['valor_neto']);
    }
}
