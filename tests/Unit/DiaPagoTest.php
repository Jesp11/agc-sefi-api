<?php

namespace Tests\Unit;

use App\Models\Credito;
use App\Support\DiaPago;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DiaPagoTest extends TestCase
{
    #[DataProvider('variantesMiercoles')]
    public function test_normaliza_variantes_de_miercoles(string $entrada): void
    {
        $this->assertSame('MIERCOLES', DiaPago::normalizar($entrada));
    }

    public static function variantesMiercoles(): array
    {
        return [
            ['miercoles'],
            ['miércoles'],
            ['Miércoles'],
            ['MIERCOLES'],
            ['MIÉRCOLES'],
            ['  miércoles  '],
        ];
    }

    public function test_el_modelo_guarda_el_dia_normalizado(): void
    {
        $credito = new Credito;
        $credito->dias_pago = 'Miércoles';

        $this->assertSame('MIERCOLES', $credito->getAttributes()['dias_pago']);
    }
}
