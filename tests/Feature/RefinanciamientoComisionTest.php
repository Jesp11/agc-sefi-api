<?php

namespace Tests\Feature;

use App\Models\Credito;
use App\Models\Refinanciamiento;
use App\Services\CicloService;
use App\Services\FlujoCajaService;
use App\Services\IndicadoresOperativosService;
use App\Services\MoraCalculationService;
use App\Services\PagoService;
use App\Services\RefinanciamientoService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class RefinanciamientoComisionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_deducts_opening_commission_from_refinancing_cash_disbursement(): void
    {
        $creditoAnterior = Credito::create([
            'id_cliente' => 'CLI-001',
            'id_asesor' => 1,
            'fecha_otorgacion' => '2026-08-01',
            'fecha_primer_pago' => '2026-08-08',
            'ciclo' => 1,
            'monto_otorgado' => 2000,
            'interes' => 0,
            'total' => 2000,
            'saldo_pendiente' => 1000,
            'plazos' => 4,
            'valor_ficha' => 500,
            'dias_pago' => 'VIERNES',
            'tipo_credito' => 'Individual',
            'estado' => 'Activo',
            'comision_apertura' => 100,
        ]);

        $mora = Mockery::mock(MoraCalculationService::class);
        $mora->shouldReceive('calculate')->twice()->andReturn(['saldo_actual' => 1000.0]);
        $ciclo = Mockery::mock(CicloService::class);
        $ciclo->shouldReceive('calcularCiclo')->once()->andReturn(2);
        $ciclo->shouldReceive('cerrarCiclo')->once();
        $ciclo->shouldReceive('registrarInicio')->once();
        $flujo = Mockery::mock(FlujoCajaService::class);
        $flujo->shouldReceive('registrarDesdeDesembolso')->once()->withArgs(
            fn (Credito $credito, float $monto, string $motivo, string $categoria) => $credito->num_prog > 0
                && $monto === 1900.0
                && $categoria === 'Renovacion'
        );
        $pago = Mockery::mock(PagoService::class);
        $indicadores = Mockery::mock(IndicadoresOperativosService::class);
        $indicadores->shouldReceive('registrarAumentoCartera')->once();

        $nuevo = (new RefinanciamientoService($mora, $ciclo, $flujo, $pago, $indicadores))->refinanciar($creditoAnterior, [
            'monto_otorgado' => 3000,
            'interes' => 300,
            'total' => 3300,
            'plazos' => 6,
            'valor_ficha' => 550,
            'fecha_efectiva' => '2026-09-02',
            'fecha_primer_pago' => '2026-09-09',
        ]);

        $this->assertSame(100.0, (float) $nuevo->comision_apertura);
        $this->assertSame($creditoAnterior->num_prog, $nuevo->credito_padre_id);
        $this->assertSame('Finalizado', $creditoAnterior->fresh()->estado);
        $this->assertSame(0.0, (float) $creditoAnterior->fresh()->saldo_pendiente);
        $this->assertDatabaseHas('refinanciamientos', [
            'num_prog_anterior' => $creditoAnterior->num_prog,
            'num_prog_nuevo' => $nuevo->num_prog,
            'saldo_anterior' => 1000.00,
            'monto_neto' => 1900.00,
        ]);
    }

    public function test_requires_new_amount_to_cover_absorbed_balance_and_commission(): void
    {
        $credito = Credito::create([
            'id_cliente' => 'CLI-001', 'id_asesor' => 1, 'fecha_otorgacion' => '2026-08-01', 'fecha_primer_pago' => '2026-08-08',
            'ciclo' => 1, 'monto_otorgado' => 1000, 'interes' => 0, 'total' => 1000, 'saldo_pendiente' => 1000, 'plazos' => 2,
            'valor_ficha' => 500, 'dias_pago' => 'VIERNES', 'tipo_credito' => 'Individual', 'estado' => 'Activo', 'comision_apertura' => 100,
        ]);
        $mora = Mockery::mock(MoraCalculationService::class);
        $mora->shouldReceive('calculate')->twice()->andReturn(['saldo_actual' => 1000.0]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('saldo absorbido y la comisión');

        (new RefinanciamientoService(
            $mora,
            Mockery::mock(CicloService::class),
            Mockery::mock(FlujoCajaService::class),
            Mockery::mock(PagoService::class),
            Mockery::mock(IndicadoresOperativosService::class),
        ))->refinanciar($credito, [
            'monto_otorgado' => 1000,
            'total' => 1100,
            'plazos' => 2,
            'valor_ficha' => 550,
            'fecha_primer_pago' => '2026-09-09',
        ]);
    }

    private function createSchema(): void
    {
        foreach (['refinanciamientos', 'pagos', 'creditos', 'clientes', 'grupos', 'asesores'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('asesores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_asesor')->nullable();
            $table->timestamps();
        });
        Schema::create('clientes', function (Blueprint $table) {
            $table->string('id_cliente')->primary();
            $table->string('nombre_completo')->nullable();
            $table->timestamps();
        });
        Schema::create('grupos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_grupo')->nullable();
            $table->timestamps();
        });
        Schema::create('creditos', function (Blueprint $table) {
            $table->id('num_prog');
            $table->string('id_cliente')->nullable();
            $table->unsignedBigInteger('id_grupo')->nullable();
            $table->unsignedBigInteger('id_asesor');
            $table->date('fecha_otorgacion');
            $table->date('fecha_primer_pago')->nullable();
            $table->integer('ciclo');
            $table->decimal('monto_otorgado', 12, 2);
            $table->decimal('interes', 12, 2);
            $table->decimal('total', 12, 2);
            $table->decimal('saldo_pendiente', 12, 2)->nullable();
            $table->integer('plazos');
            $table->decimal('valor_ficha', 12, 2);
            $table->string('dias_pago');
            $table->string('tipo_credito');
            $table->string('estado');
            $table->decimal('comision_apertura', 12, 2)->nullable();
            $table->unsignedBigInteger('credito_padre_id')->nullable();
            $table->string('tasa_asignada')->nullable();
            $table->decimal('porcentaje_interes', 8, 2)->nullable();
            $table->boolean('es_personalizado')->default(false);
            $table->date('fecha_programada_renovacion')->nullable();
            $table->string('renovacion_autorizada')->nullable();
            $table->string('renovacion_tasa')->nullable();
            $table->timestamps();
        });
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('num_prog');
            $table->decimal('monto', 12, 2);
            $table->date('fecha')->nullable();
            $table->time('hora')->nullable();
            $table->string('tipo')->nullable();
            $table->timestamps();
        });
        Schema::create('refinanciamientos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('num_prog_anterior');
            $table->unsignedBigInteger('num_prog_nuevo');
            $table->decimal('saldo_anterior', 12, 2);
            $table->decimal('deduccion', 12, 2);
            $table->decimal('monto_neto', 12, 2);
            $table->decimal('intereses_arrastrados', 12, 2)->default(0);
            $table->date('fecha_efectiva')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }
}
