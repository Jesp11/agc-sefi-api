<?php

namespace Tests\Feature;

use App\Services\BalanceMoraMensualService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BalanceMoraMensualTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('balance_mora_mensual');

        parent::tearDown();
    }

    public function test_importa_los_ocho_meses_de_mora_de_la_referencia(): void
    {
        $migration = require database_path('migrations/2026_09_25_000003_create_balance_mora_mensual.php');
        $migration->up();

        $rows = \Illuminate\Support\Facades\DB::table('balance_mora_mensual')
            ->orderBy('mes')
            ->get(['mes', 'mora_activa', 'mora_muerta'])
            ->map(fn ($row) => [$row->mes, (float) $row->mora_activa, (float) $row->mora_muerta])
            ->all();

        $this->assertSame([
            ['2026-01', 54245.0, 0.0],
            ['2026-02', 94761.0, 0.0],
            ['2026-03', 103703.0, 0.0],
            ['2026-04', 103011.0, 0.0],
            ['2026-05', 100831.0, 0.0],
            ['2026-06', 84930.0, 15901.0],
            ['2026-07', 66669.0, 43271.0],
            ['2026-08', 19797.0, 109940.0],
        ], $rows);
    }

    public function test_la_serie_anual_prioriza_la_historia_y_el_cierre_sobre_el_mes_en_curso(): void
    {
        $migration = require database_path('migrations/2026_09_25_000003_create_balance_mora_mensual.php');
        $migration->up();
        $service = app(BalanceMoraMensualService::class);

        $serie = $service->serieAnual(2026, '2026-09', 12000, 90000);
        $this->assertCount(12, $serie);
        $this->assertSame(['mes' => '2026-08', 'mora_activa' => 19797.0, 'mora_muerta' => 109940.0, 'origen' => 'historico'], $serie[7]);
        $this->assertSame(['mes' => '2026-09', 'mora_activa' => 12000.0, 'mora_muerta' => 90000.0, 'origen' => 'en_curso'], $serie[8]);
        $this->assertNull($serie[9]['mora_activa']);

        $service->registrarCierre('2026-09', 13000, 91000);
        $service->registrarCierre('2026-08', 1, 1);
        $serie = $service->serieAnual(2026, '2026-09', 12000, 90000);

        $this->assertSame('cierre', $serie[8]['origen']);
        $this->assertSame(13000.0, $serie[8]['mora_activa']);
        $this->assertSame(109940.0, $serie[7]['mora_muerta']);
    }
}
