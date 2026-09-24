<?php

namespace Tests\Unit;

use App\Models\Inversionista;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InversionistaTest extends TestCase
{
    #[DataProvider('rendimientosMensuales')]
    public function test_calcula_el_rendimiento_mensual_con_la_tasa_del_inversionista(
        float $capital,
        float $tasa,
        float $esperado,
    ): void {
        $inversionista = new Inversionista(['tasa_mensual' => $tasa]);

        $this->assertSame($esperado, $inversionista->calcularRendimientoMensual($capital));
    }

    public static function rendimientosMensuales(): array
    {
        return [
            'cuatro por ciento' => [50_000, 4, 2_000.00],
            'tasa con decimales' => [85_000, 5.25, 4_462.50],
            'sin tasa' => [100_000, 0, 0.00],
            'capital negativo no genera rendimiento' => [-10_000, 4, 0.00],
        ];
    }
}
