<?php

namespace Tests\Feature;

use App\Models\AhorroPersonal;
use App\Models\AhorroPersonalMovimiento;
use App\Models\CicloHistorial;
use App\Models\Credito;
use App\Models\IndicadorOperativoEvento;
use App\Models\MovimientoCaja;
use App\Models\Pago;
use App\Services\CreditoEliminacionDesactualizadaException;
use App\Services\CreditoEliminacionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreditoEliminacionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'movimientos_caja', 'ahorro_personal_movimientos', 'ahorros_personal',
            'documentos_credito', 'indicadores_operativos_eventos', 'ciclos_historial',
            'pagos', 'refinanciamientos', 'creditos', 'clientes', 'grupos', 'asesores',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('asesores', fn (Blueprint $table) => $table->id());
        Schema::create('clientes', function (Blueprint $table) {
            $table->string('id_cliente')->primary();
            $table->string('nombre_completo')->nullable();
            $table->timestamps();
        });
        Schema::create('grupos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_grupo')->nullable();
        });
        Schema::create('creditos', function (Blueprint $table) {
            $table->id('num_prog');
            $table->string('id_cliente')->nullable();
            $table->unsignedBigInteger('id_grupo')->nullable();
            $table->unsignedBigInteger('id_asesor')->nullable();
            $table->date('fecha_otorgacion');
            $table->decimal('monto_otorgado', 12, 2);
            $table->decimal('comision_apertura', 12, 2)->nullable();
            $table->string('tipo_credito')->nullable();
            $table->unsignedBigInteger('credito_padre_id')->nullable();
            $table->timestamps();
        });
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('num_prog');
            $table->decimal('monto', 12, 2);
            $table->decimal('ahorro_personal_monto', 12, 2)->default(0);
            $table->date('fecha');
            $table->time('hora')->nullable();
            $table->string('metodo_pago')->nullable();
            $table->string('tipo')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });
        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->text('motivo');
            $table->string('tipo');
            $table->decimal('monto', 14, 2);
            $table->decimal('saldo_resultante', 14, 2)->nullable();
            $table->string('categoria')->nullable();
            $table->unsignedBigInteger('num_prog')->nullable();
            $table->unsignedBigInteger('pago_id')->nullable();
            $table->string('referencia')->nullable();
            $table->timestamps();
        });
        Schema::create('ahorros_personal', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asesor_id')->nullable();
            $table->decimal('saldo', 12, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('ahorro_personal_movimientos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ahorro_personal_id');
            $table->string('tipo');
            $table->decimal('monto', 12, 2);
            $table->date('fecha');
            $table->text('notas')->nullable();
            $table->timestamps();
        });
        Schema::create('documentos_credito', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('num_prog');
            $table->string('tipo');
            $table->string('nombre_archivo');
            $table->string('ruta');
            $table->timestamps();
        });
        Schema::create('ciclos_historial', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('num_prog');
            $table->integer('ciclo');
            $table->string('resultado');
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->timestamps();
        });
        Schema::create('indicadores_operativos_eventos', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->string('tipo');
            $table->decimal('monto', 14, 2)->default(0);
            $table->unsignedBigInteger('num_prog')->nullable();
            $table->unsignedBigInteger('num_prog_relacionado')->nullable();
            $table->string('origen')->nullable();
            $table->timestamps();
        });
        Schema::create('refinanciamientos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('num_prog_anterior');
            $table->unsignedBigInteger('num_prog_nuevo');
            $table->timestamps();
        });
    }

    public function test_it_reverses_only_explicitly_linked_effects_and_keeps_a_possible_match(): void
    {
        $credito = $this->crearCredito();
        MovimientoCaja::create(['fecha' => '2026-01-01', 'motivo' => 'Saldo inicial', 'tipo' => 'Ingreso', 'monto' => 1000, 'saldo_resultante' => 1000, 'categoria' => 'SaldoInicial']);
        $desembolso = MovimientoCaja::create(['fecha' => '2026-01-02', 'motivo' => 'Desembolso', 'tipo' => 'Egreso', 'monto' => 400, 'saldo_resultante' => 600, 'num_prog' => $credito->num_prog, 'referencia' => "DESEMBOLSO-{$credito->num_prog}"]);
        $coincidencia = MovimientoCaja::create(['fecha' => '2026-01-02', 'motivo' => 'Movimiento no vinculado', 'tipo' => 'Egreso', 'monto' => 400, 'saldo_resultante' => 200]);
        $pago = Pago::create(['num_prog' => $credito->num_prog, 'monto' => 100, 'ahorro_personal_monto' => 20, 'fecha' => '2026-01-03', 'hora' => '10:00:00', 'tipo' => 'Abono']);
        $ingreso = MovimientoCaja::create(['fecha' => '2026-01-03', 'motivo' => 'Cobro', 'tipo' => 'Ingreso', 'monto' => 100, 'saldo_resultante' => 300, 'num_prog' => $credito->num_prog, 'pago_id' => $pago->id]);
        $manual = MovimientoCaja::create(['fecha' => '2026-01-04', 'motivo' => 'Corrección ligada', 'tipo' => 'Ingreso', 'monto' => 10, 'saldo_resultante' => 310, 'num_prog' => $credito->num_prog]);
        $ahorro = AhorroPersonal::create(['asesor_id' => 1, 'saldo' => 20]);
        $ahorroMovimiento = AhorroPersonalMovimiento::create(['ahorro_personal_id' => $ahorro->id, 'tipo' => 'Ingreso', 'monto' => 20, 'fecha' => '2026-01-03', 'notas' => "Ahorro desde abono #{$pago->id} / crédito #{$credito->num_prog}"]);
        $ciclo = CicloHistorial::create(['num_prog' => $credito->num_prog, 'ciclo' => 1, 'resultado' => 'Activo', 'fecha_inicio' => '2026-01-02']);
        $indicador = IndicadorOperativoEvento::create(['fecha' => '2026-01-02', 'tipo' => 'AumentoCartera', 'monto' => 400, 'num_prog' => $credito->num_prog]);

        $service = app(CreditoEliminacionService::class);
        $preview = $service->preview($credito);
        $this->assertSame([$coincidencia->id], array_column($preview['coincidencias_posibles'], 'id'));
        $this->assertSame([$manual->id], array_column($preview['impactos']['movimientos_manuales'], 'id'));

        $service->eliminar($credito->num_prog, $preview['huella']);

        $this->assertDatabaseMissing('creditos', ['num_prog' => $credito->num_prog]);
        $this->assertDatabaseMissing('pagos', ['id' => $pago->id]);
        $this->assertDatabaseMissing('movimientos_caja', ['id' => $desembolso->id]);
        $this->assertDatabaseMissing('movimientos_caja', ['id' => $ingreso->id]);
        $this->assertDatabaseMissing('movimientos_caja', ['id' => $manual->id]);
        $this->assertDatabaseHas('movimientos_caja', ['id' => $coincidencia->id]);
        $this->assertDatabaseMissing('ahorro_personal_movimientos', ['id' => $ahorroMovimiento->id]);
        $this->assertDatabaseHas('ahorros_personal', ['id' => $ahorro->id, 'saldo' => 0]);
        $this->assertDatabaseMissing('ciclos_historial', ['id' => $ciclo->id]);
        $this->assertDatabaseMissing('indicadores_operativos_eventos', ['id' => $indicador->id]);
        $this->assertDatabaseHas('movimientos_caja', ['id' => $coincidencia->id, 'saldo_resultante' => 600]);
    }

    public function test_it_rejects_deletion_when_impact_changes_after_preview(): void
    {
        $credito = $this->crearCredito();
        $service = app(CreditoEliminacionService::class);
        $preview = $service->preview($credito);
        MovimientoCaja::create(['fecha' => '2026-01-02', 'motivo' => 'Nuevo vínculo', 'tipo' => 'Ingreso', 'monto' => 1, 'num_prog' => $credito->num_prog]);

        $this->expectException(CreditoEliminacionDesactualizadaException::class);
        $service->eliminar($credito->num_prog, $preview['huella']);
    }

    public function test_preview_blocks_a_credit_that_participates_in_a_renewal(): void
    {
        $credito = $this->crearCredito();
        $credito->update(['credito_padre_id' => 999]);

        $preview = app(CreditoEliminacionService::class)->preview($credito->fresh());

        $this->assertTrue($preview['bloqueado']);
        $this->assertStringContainsString('renovación', $preview['motivo_bloqueo']);
    }

    private function crearCredito(): Credito
    {
        return Credito::create([
            'id_cliente' => 'CLI-1',
            'id_asesor' => 1,
            'fecha_otorgacion' => '2026-01-02',
            'monto_otorgado' => 500,
            'comision_apertura' => 100,
            'tipo_credito' => 'Individual',
        ]);
    }
}
