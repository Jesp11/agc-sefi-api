<?php

namespace Tests\Unit;

use App\Models\Credito;
use App\Services\MoraCalculationService;
use App\Support\CalendarioQuincenal;
use PHPUnit\Framework\TestCase;

class MoraCalculationScheduleTest extends TestCase
{
    public function test_calendario_semanal_por_defecto(): void
    {
        $credito = new Credito([
            'fecha_primer_pago' => '2026-10-02',
            'plazos' => 3,
            'valor_ficha' => 500,
        ]);

        $fechas = array_column((new MoraCalculationService())->generateSchedule($credito), 'fecha');

        $this->assertSame(['2026-10-02', '2026-10-09', '2026-10-16'], $fechas);
    }

    public function test_calendario_quincenal_en_dias_fijos_del_mes(): void
    {
        $credito = new Credito([
            'fecha_primer_pago' => '2026-10-15',
            'plazos' => 4,
            'valor_ficha' => 1000,
            'frecuencia_pago' => Credito::FRECUENCIA_QUINCENAL,
            'dia_quincena_1' => 15,
            'dia_quincena_2' => 30,
        ]);

        $fechas = array_column((new MoraCalculationService())->generateSchedule($credito), 'fecha');

        $this->assertSame(['2026-10-15', '2026-10-30', '2026-11-15', '2026-11-30'], $fechas);
    }

    public function test_calendario_quincenal_ajusta_dias_inexistentes_al_fin_de_mes(): void
    {
        $credito = new Credito([
            'fecha_primer_pago' => '2027-01-30',
            'plazos' => 4,
            'valor_ficha' => 1000,
            'frecuencia_pago' => Credito::FRECUENCIA_QUINCENAL,
            'dia_quincena_1' => 15,
            'dia_quincena_2' => 30,
        ]);

        $fechas = array_column((new MoraCalculationService())->generateSchedule($credito), 'fecha');

        $this->assertSame(['2027-01-30', '2027-02-15', '2027-02-28', '2027-03-15'], $fechas);
    }

    public function test_calendario_quincenal_sin_dias_usa_15_y_30(): void
    {
        $credito = new Credito([
            'fecha_primer_pago' => '2026-10-30',
            'plazos' => 2,
            'valor_ficha' => 1000,
            'frecuencia_pago' => Credito::FRECUENCIA_QUINCENAL,
        ]);

        $fechas = array_column((new MoraCalculationService())->generateSchedule($credito), 'fecha');

        $this->assertSame(['2026-10-30', '2026-11-15'], $fechas);
    }

    public function test_valida_si_una_fecha_es_dia_de_pago_quincenal(): void
    {
        $this->assertTrue(CalendarioQuincenal::esDiaDePago('2026-10-15', 15, 30));
        $this->assertTrue(CalendarioQuincenal::esDiaDePago('2027-02-28', 15, 30));
        $this->assertFalse(CalendarioQuincenal::esDiaDePago('2026-10-31', 15, 30));
        $this->assertFalse(CalendarioQuincenal::esDiaDePago('2026-10-16', 15, 30));
    }
}
